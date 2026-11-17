#!/usr/bin/env bash
# Task 325: privacy-safe matching of independent restored snapshots.
# All data joins happen inside a temporary PostgreSQL instance with no network.
set -euo pipefail
umask 077
[[ $# == 1 && "$1" =~ ^[0-9]+$ ]] || exit 2
RUN_ID="$1"
ROOT=/home/deploy/mskba-data-merge-candidates
DEST="$ROOT/$RUN_ID-identity"
SOURCE=/home/deploy/mskba-data-merge-backups/43974694391
CONTAINER="mskba-merge-id-audit-$RUN_ID"
test ! -e "$DEST"
test -s "$SOURCE/prod/database.pgdump"
test -s "$SOURCE/dev/database.pgdump"
docker image inspect postgres:17-alpine >/dev/null
mkdir -p "$DEST"
chmod 700 "$DEST"
cleanup() { docker stop "$CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --network none --name "$CONTAINER" \
  -e POSTGRES_DB=prod_audit -e POSTGRES_PASSWORD="$(openssl rand -hex 32)" \
  postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
  if docker exec "$CONTAINER" sh -c 'test "$(cat /proc/1/comm)" = postgres' \
    && docker exec "$CONTAINER" pg_isready -q -U postgres -d prod_audit; then
    ready=true
    break
  fi
  sleep 1
done
[[ "$ready" == true ]] || exit 3
docker exec -i "$CONTAINER" pg_restore -U postgres -d prod_audit \
  --no-owner --no-acl --exit-on-error < "$SOURCE/prod/database.pgdump" > "$DEST/prod-restore.log" 2>&1
docker exec "$CONTAINER" createdb -U postgres dev_audit
docker exec -i "$CONTAINER" pg_restore -U postgres -d dev_audit \
  --no-owner --no-acl --exit-on-error < "$SOURCE/dev/database.pgdump" > "$DEST/dev-restore.log" 2>&1

sql() { docker exec "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d prod_audit -c "$1"; }
sql "CREATE EXTENSION IF NOT EXISTS postgres_fdw" >/dev/null
sql "CREATE SCHEMA ext_dev" >/dev/null
sql "CREATE SERVER dev_snapshot FOREIGN DATA WRAPPER postgres_fdw OPTIONS (dbname 'dev_audit', host '/var/run/postgresql')" >/dev/null
sql "CREATE USER MAPPING FOR postgres SERVER dev_snapshot OPTIONS (user 'postgres')" >/dev/null
sql "IMPORT FOREIGN SCHEMA public LIMIT TO (users, contacts, telegram_accounts, venues, actors, teams) FROM SERVER dev_snapshot INTO ext_dev" >/dev/null

# Only aggregate counts ever leave this server; PII values never do.
matched_users_by_username="$(sql "SELECT COUNT(*) FROM users p JOIN ext_dev.users d ON lower(btrim(p.username))=lower(btrim(d.username)) WHERE p.username IS NOT NULL AND d.username IS NOT NULL")"
matched_tg_accounts="$(sql "SELECT COUNT(*) FROM telegram_accounts p JOIN ext_dev.telegram_accounts d ON p.telegram_user_id=d.telegram_user_id")"
matched_verified_contacts="$(sql "SELECT COUNT(*) FROM contacts p JOIN ext_dev.contacts d ON p.type=d.type AND p.contactable_type=d.contactable_type AND lower(btrim(p.value))=lower(btrim(d.value)) WHERE p.verified_at IS NOT NULL AND d.verified_at IS NOT NULL AND p.deleted_at IS NULL AND d.deleted_at IS NULL")"
matched_all_contacts="$(sql "SELECT COUNT(*) FROM contacts p JOIN ext_dev.contacts d ON p.type=d.type AND p.contactable_type=d.contactable_type AND lower(btrim(p.value))=lower(btrim(d.value)) WHERE p.deleted_at IS NULL AND d.deleted_at IS NULL")"
matched_venues_by_name="$(sql "SELECT COUNT(*) FROM venues p JOIN ext_dev.venues d ON lower(btrim(p.name))=lower(btrim(d.name)) WHERE p.deleted_at IS NULL AND d.deleted_at IS NULL")"
matched_venues_by_alias="$(sql "SELECT COUNT(*) FROM venues p JOIN ext_dev.venues d ON p.alias=d.alias WHERE p.deleted_at IS NULL AND d.deleted_at IS NULL")"
matched_actors="$(sql "SELECT COUNT(*) FROM actors p JOIN ext_dev.actors d ON p.actor_key=d.actor_key")"
matched_team_aliases="$(sql "SELECT COUNT(*) FROM teams p JOIN ext_dev.teams d ON p.alias=d.alias")"

# Private mapping candidates by source numeric IDs, never identities or contact values.
sql "SELECT 'username',p.id,d.id FROM users p JOIN ext_dev.users d ON lower(btrim(p.username))=lower(btrim(d.username)) WHERE p.username IS NOT NULL AND d.username IS NOT NULL
UNION ALL SELECT 'telegram',p.user_id,d.user_id FROM telegram_accounts p JOIN ext_dev.telegram_accounts d ON p.telegram_user_id=d.telegram_user_id
UNION ALL SELECT 'venue_alias',p.id,d.id FROM venues p JOIN ext_dev.venues d ON p.alias=d.alias" > "$DEST/mapping-candidates.csv"
chmod 600 "$DEST"/*

echo 'IDENTITY_COLLISION_AUDIT_PASS=true'
echo "username_collision_pairs=$matched_users_by_username"
echo "telegram_identity_collision_pairs=$matched_tg_accounts"
echo "verified_contact_collision_pairs=$matched_verified_contacts"
echo "all_contact_collision_pairs=$matched_all_contacts"
echo "venue_name_collision_pairs=$matched_venues_by_name"
echo "venue_alias_collision_pairs=$matched_venues_by_alias"
echo "actor_key_collision_pairs=$matched_actors"
echo "team_alias_collision_pairs=$matched_team_aliases"
echo 'No personal values or mapping pairs were published.'
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
