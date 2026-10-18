#!/usr/bin/env bash
# One-time public venue fixture: production (read-only) -> DEV.
# Source IDs: 1 (School 1794), 9 (Fili DeloSport), 15 (Akademicheskaya).
# Execute manually on the server as deploy. DO NOT run in an auto-deploy hook.
set -euo pipefail
umask 077

PROD=/var/www/mskba
DEV=/var/www/mskba-dev-next
OUT="$HOME/mskba-dev-venue-seeds/$(date -u +%Y%m%dT%H%M%SZ)"

[[ "$(id -un)" == "deploy" ]] || { echo 'Aborting: expected deploy user'; exit 1; }
[[ -r "$PROD/compose.yaml" && -r "$PROD/compose.prod.yaml" ]] || { echo 'Production compose files not found'; exit 1; }
[[ -r "$DEV/compose.dev.yaml" && -r "$DEV/compose.dev.local.yaml" && -r "$DEV/.env" ]] || { echo 'DEV configuration not found'; exit 1; }
grep -qx 'APP_ENV=staging' "$DEV/.env" || { echo 'Aborting: not staging'; exit 1; }
grep -qx 'APP_URL=https://dev.mskba.ru' "$DEV/.env" || { echo 'Aborting: wrong DEV domain'; exit 1; }

prod() { ( cd "$PROD" && docker compose -f compose.yaml -f compose.prod.yaml "$@" ); }
dev() { ( cd "$DEV" && docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml "$@" ); }

mkdir -p -m 700 "$OUT"
CSV="$OUT/venue-public-data.csv"
BACKUP="$OUT/dev-before-venue-import.dump"
IMPORT="$OUT/import-venues.sql"

echo '1/5 Exporting exactly 3 public source venue rows (production: SELECT-only)...'
prod exec -T db sh -c '
  exec env PGOPTIONS="-c default_transaction_read_only=on" \
    psql -X -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -c "COPY (
      SELECT row_to_json(x) FROM (
        SELECT id, name, alias, type, status, operational_status,
               requires_payment, requires_booking_approval,
               short_description, full_description, raw_address
        FROM venues
        WHERE id IN (1, 9, 15) AND status = '\''confirmed'\'' AND deleted_at IS NULL
        ORDER BY id
      ) x
    ) TO STDOUT WITH (FORMAT csv)"
' > "$CSV"
[[ $(wc -l < "$CSV") -eq 3 ]] || { echo 'Expected exactly 3 production venues, aborting'; exit 1; }

echo '2/5 Making full DEV PostgreSQL backup (no production data copied into the backup)...'
dev exec -T db sh -c '
  exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner --no-acl
' > "$BACKUP"
[[ -s "$BACKUP" ]] || { echo 'DEV backup empty: abort'; exit 1; }

echo '3/5 Preparing validated transactional import...'
cat > "$IMPORT" <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
CREATE TEMP TABLE seed_venues (payload jsonb) ON COMMIT DROP;
COPY seed_venues (payload) FROM STDIN WITH (FORMAT csv);
SQL
cat "$CSV" >> "$IMPORT"
printf '\\.\n' >> "$IMPORT"
cat >> "$IMPORT" <<'SQL'
DO $assert$
DECLARE actual_ids bigint[];
BEGIN
  SELECT array_agg((payload->>'id')::bigint ORDER BY (payload->>'id')::bigint)
    INTO actual_ids FROM seed_venues;
  IF actual_ids IS DISTINCT FROM ARRAY[1,9,15]::bigint[] THEN
    RAISE EXCEPTION 'Unexpected source venue IDs: %', actual_ids;
  END IF;
  IF (SELECT payload->>'name' FROM seed_venues WHERE (payload->>'id')::bigint = 1) IS DISTINCT FROM 'Школа №1794'
     OR (SELECT payload->>'name' FROM seed_venues WHERE (payload->>'id')::bigint = 9) IS DISTINCT FROM 'Фили ДелоСпорт на Новозаводской'
     OR (SELECT payload->>'name' FROM seed_venues WHERE (payload->>'id')::bigint = 15) IS DISTINCT FROM 'Площадка. Метро Академическая' THEN
    RAISE EXCEPTION 'Production venue names differ from the approved fixture';
  END IF;
  IF EXISTS (SELECT 1 FROM venues) THEN
    RAISE EXCEPTION 'DEV venues is no longer empty; aborting rather than overwriting records';
  END IF;
  IF EXISTS (SELECT 1 FROM seed_venues WHERE payload->>'status' <> 'confirmed') THEN
    RAISE EXCEPTION 'Source includes a non-public venue';
  END IF;
END
$assert$;

INSERT INTO venues (
    id, name, alias, type, status, operational_status,
    requires_payment, requires_booking_approval,
    short_description, full_description, raw_address,
    created_by_actor_id, location_id, canonical_venue_id,
    created_at, updated_at
)
SELECT
    (payload->>'id')::bigint,
    payload->>'name',
    payload->>'alias',
    payload->>'type',
    payload->>'status',
    COALESCE(payload->>'operational_status', 'active'),
    (payload->>'requires_payment')::boolean,
    COALESCE((payload->>'requires_booking_approval')::boolean, false),
    payload->>'short_description',
    payload->>'full_description',
    payload->>'raw_address',
    NULL, NULL, NULL,
    now(), now()
FROM seed_venues
ORDER BY (payload->>'id')::bigint;

SELECT setval(pg_get_serial_sequence('venues','id'), (SELECT MAX(id) FROM venues), true);
COMMIT;

SELECT id, name, type, status, raw_address, created_by_actor_id, location_id
FROM venues WHERE id IN (1,9,15) ORDER BY id;
SQL

echo '4/5 Importing into DEV only...'
dev exec -T db sh -c '
  exec psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"
' < "$IMPORT"

echo '5/5 Checking API. An empty response or HTTP error is a failure.'
API_JSON="$OUT/api-page-1.json"
curl --fail --silent --show-error --max-time 20 \
  'https://dev.mskba.ru/api/v1/venues?limit=2' -o "$API_JSON"
python3 - "$API_JSON" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as fp:
    response = json.load(fp)
ids = [item['id'] for item in response['data']]
assert len(ids) == 2, f"Expected 2 venues on first page, got {ids}"
assert response['meta']['has_more'] is True, response['meta']
assert response['meta']['next_cursor'], response['meta']
print('API page 1 IDs:', ids)
print('next_cursor:', response['meta']['next_cursor'])
PY

CURSOR="$(python3 - "$API_JSON" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as fp:
    print(json.load(fp)['meta']['next_cursor'])
PY
)"
API_JSON_2="$OUT/api-page-2.json"
curl --get --fail --silent --show-error --max-time 20 \
  --data-urlencode "limit=2" --data-urlencode "cursor=$CURSOR" \
  'https://dev.mskba.ru/api/v1/venues' -o "$API_JSON_2"
python3 - "$API_JSON" "$API_JSON_2" <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as fp:
    page1 = json.load(fp)
with open(sys.argv[2], encoding='utf-8') as fp:
    page2 = json.load(fp)
ids = [row['id'] for page in (page1, page2) for row in page['data']]
assert ids == [1, 9, 15], f"Unexpected venue sequence: {ids}"
assert page2['meta']['has_more'] is False, page2['meta']
assert page2['meta']['next_cursor'] is None
print('API page 2 IDs:', [row['id'] for row in page2['data']])
print('PASS: 3 confirmed public venues across 2 cursor pages')
PY

echo "Done. Backup and operation files (private mode): $OUT"
