#!/usr/bin/env bash
# Task 325: count all non-empty dev tables and verify venue matching geography
# only in networkless restored snapshots. Never operate on live databases.
set -euo pipefail
umask 077
[[ $# == 1 && "$1" =~ ^[0-9]+$ ]] || exit 2
ID="$1"
BASE=/home/deploy/mskba-data-merge-candidates
DEST="$BASE/$ID-scope"
PROD="$BASE/43980325816/candidate-migrated.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
CONTAINER="mskba-merge-scope-$ID"
test ! -e "$DEST"
test -s "$PROD"
test -s "$DEV"
mkdir -p "$DEST"
chmod 700 "$DEST"
cleanup() { docker stop "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --network none --name "$CONTAINER" \
  -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$(openssl rand -hex 32)" \
  postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
  if docker exec "$CONTAINER" sh -c 'test "$(cat /proc/1/comm)" = postgres' \
    && docker exec "$CONTAINER" pg_isready -q -U postgres -d candidate; then
    ready=true; break
  fi
  sleep 1
done
[[ "$ready" == true ]] || exit 3
docker exec -i "$CONTAINER" pg_restore -U postgres -d candidate --no-owner --no-acl --exit-on-error < "$PROD" > "$DEST/restore-candidate.log" 2>&1
docker exec "$CONTAINER" createdb -U postgres dev_snapshot
docker exec -i "$CONTAINER" pg_restore -U postgres -d dev_snapshot --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/restore-dev.log" 2>&1

# Restore two DBs in one ephemeral instance. Foreign tables connect only via
# that instance's private Unix socket, never to live prod or dev.
docker exec -i "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate > "$DEST/table-counts.tsv" <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgres_fdw;
CREATE SCHEMA ext_dev;
CREATE SERVER dev_snapshot FOREIGN DATA WRAPPER postgres_fdw OPTIONS (dbname 'dev_snapshot', host '/var/run/postgresql');
CREATE USER MAPPING FOR postgres SERVER dev_snapshot OPTIONS (user 'postgres');
IMPORT FOREIGN SCHEMA public FROM SERVER dev_snapshot INTO ext_dev;
SELECT format(
  'SELECT %L || ''|'' || (SELECT count(*) FROM ext_dev.%I) || ''|'' || (SELECT count(*) FROM public.%I);',
  t.tablename, t.tablename, t.tablename
)
FROM pg_catalog.pg_tables t
WHERE t.schemaname='public' AND EXISTS (
 SELECT 1 FROM information_schema.foreign_tables f
 WHERE f.foreign_table_schema='ext_dev' AND f.foreign_table_name=t.tablename
)
ORDER BY t.tablename
\gexec
SQL

# Geo distance computed with real coordinates but only counts and source IDs are
# retained privately. Public logs never reveal address or coordinates.
docker exec -i "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate > "$DEST/venue-geo-summary.txt" <<'SQL'
WITH pairs AS (
 SELECT p.id AS prod_id, d.id AS dev_id,
        p.type = d.type AS same_type,
        lower(btrim(p.name)) = lower(btrim(d.name)) AS same_name,
        p.alias = d.alias AS same_alias,
        CASE WHEN pa.latitude IS NOT NULL AND pa.longitude IS NOT NULL
          AND da.latitude IS NOT NULL AND da.longitude IS NOT NULL
          THEN 6371000 * acos(least(1, greatest(-1,
            sin(radians(pa.latitude::float8))*sin(radians(da.latitude::float8)) +
            cos(radians(pa.latitude::float8))*cos(radians(da.latitude::float8))*
            cos(radians(pa.longitude::float8-da.longitude::float8))
          )))
          ELSE NULL END AS meters
 FROM venues p CROSS JOIN ext_dev.venues d
 LEFT JOIN locations pl ON pl.id=p.location_id
 LEFT JOIN addresses pa ON pa.id=pl.address_id
 LEFT JOIN ext_dev.locations dl ON dl.id=d.location_id
 LEFT JOIN ext_dev.addresses da ON da.id=dl.address_id
 WHERE p.deleted_at IS NULL AND d.deleted_at IS NULL
)
SELECT 'venue_geo_under_50m_same_type=' || count(*) FROM pairs WHERE same_type AND meters <= 50
UNION ALL SELECT 'venue_geo_under_200m_same_type=' || count(*) FROM pairs WHERE same_type AND meters <= 200
UNION ALL SELECT 'venue_same_name_under_50m=' || count(*) FROM pairs WHERE same_name AND same_type AND meters <= 50
UNION ALL SELECT 'venue_same_alias_under_50m=' || count(*) FROM pairs WHERE same_alias AND same_type AND meters <= 50
UNION ALL SELECT 'venue_same_name_distant_over_200m=' || count(*) FROM pairs WHERE same_name AND meters > 200
UNION ALL SELECT 'venue_same_name_no_coordinates=' || count(*) FROM pairs WHERE same_name AND meters IS NULL
UNION ALL SELECT 'venue_unmatched_dev_50m=' || count(*) FROM ext_dev.venues d
WHERE d.deleted_at IS NULL AND NOT EXISTS (
 SELECT 1 FROM pairs x WHERE x.dev_id=d.id AND x.same_type AND x.meters <= 50
);
SQL

# The same geometry can yield multiple plausible matches: a human-reviewed
# mapping table must be resolved before assigning ownership and media.
docker exec -i "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate > "$DEST/venue-mapping-private.tsv" <<'SQL'
SELECT p.id,d.id,round(6371000*acos(least(1,greatest(-1,
 sin(radians(pa.latitude::float8))*sin(radians(da.latitude::float8))
 + cos(radians(pa.latitude::float8))*cos(radians(da.latitude::float8))
 * cos(radians(pa.longitude::float8-da.longitude::float8))))))::int AS meters
FROM venues p JOIN locations pl ON pl.id=p.location_id
JOIN addresses pa ON pa.id=pl.address_id
CROSS JOIN ext_dev.venues d JOIN ext_dev.locations dl ON dl.id=d.location_id
JOIN ext_dev.addresses da ON da.id=dl.address_id
WHERE pa.latitude IS NOT NULL AND da.latitude IS NOT NULL
AND pa.longitude IS NOT NULL AND da.longitude IS NOT NULL
AND p.type=d.type AND p.deleted_at IS NULL AND d.deleted_at IS NULL
AND abs(pa.latitude-da.latitude)<0.003 AND abs(pa.longitude-da.longitude)<0.003
ORDER BY d.id,meters;
SQL
chmod 600 "$DEST"/*
echo 'SCOPE_INVENTORY_PASS=true'
awk -F'|' 'NF==3 && $2+0>0 {printf "table.%s dev=%d target=%d\n",$1,$2,$3}' "$DEST/table-counts.tsv"
cat "$DEST/venue-geo-summary.txt"
echo 'Detailed venue mapping stays server-private.'
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
