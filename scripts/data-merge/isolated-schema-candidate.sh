#!/usr/bin/env bash
# Phase C of task 325: migrate an OFFLINE copy of production data using dev code.
# Source DBs, source volumes, live compose services and live .env remain untouched.
set -euo pipefail
umask 077

if [[ $# -ne 1 || ! "$1" =~ ^[0-9]+$ ]]; then
  echo 'Usage: isolated-schema-candidate.sh <github-run-id>' >&2
  exit 2
fi
RUN_ID="$1"
DEV_ROOT=/var/www/mskba-dev-next
PROD_ROOT=/var/www/mskba
SOURCE=/home/deploy/mskba-data-merge-backups/43974694391/prod
ROOT=/home/deploy/mskba-data-merge-candidates
DEST="$ROOT/$RUN_ID"
NET="mskba-candidate-net-$RUN_ID"
DB_CONTAINER="mskba-candidate-db-$RUN_ID"

test -d "$DEV_ROOT/vendor"
test -f "$DEV_ROOT/artisan"
test -f "$DEV_ROOT/.env"
test -f "$SOURCE/database.pgdump"
test -f "$SOURCE/SHA256SUMS"
test -d "$PROD_ROOT"
grep -Fxq 'APP_URL=https://dev.mskba.ru' "$DEV_ROOT/.env"
grep -Fxq 'APP_ENV=staging' "$DEV_ROOT/.env"
grep -Fxq 'APP_URL=https://mskba.ru' "$PROD_ROOT/.env"
test ! -e "$DEST"
sha256sum --status -c "$SOURCE/SHA256SUMS"
docker image inspect postgres:17-alpine > /dev/null
docker image inspect mskbanew-phpfpm:latest > /dev/null

mkdir -p "$DEST/storage/framework/cache/data" "$DEST/storage/framework/sessions" \
  "$DEST/storage/framework/views" "$DEST/storage/logs" "$DEST/bootstrap-cache"
chmod 700 "$ROOT" "$DEST"
DB_PASS="$(openssl rand -hex 32)"
APP_SECRET="base64:$(openssl rand -base64 32 | tr -d '\n')"
cleanup() {
  docker stop "$DB_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# No host ports, volumes, live networks, or internet-facing services.
docker network create --internal "$NET" > /dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db \
  --name "$DB_CONTAINER" -e POSTGRES_DB=mskba_candidate \
  -e POSTGRES_PASSWORD="$DB_PASS" postgres:17-alpine > /dev/null

ready=false
for _ in $(seq 1 60); do
  if docker exec "$DB_CONTAINER" sh -c 'test "$(cat /proc/1/comm)" = postgres' \
    && docker exec "$DB_CONTAINER" pg_isready -q -U postgres -d mskba_candidate; then
    ready=true
    break
  fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'CANDIDATE_BLOCKED database start' >&2; exit 3; }

if ! docker exec -i "$DB_CONTAINER" pg_restore -U postgres -d mskba_candidate \
  --no-owner --no-acl --exit-on-error < "$SOURCE/database.pgdump" > "$DEST/restore.log" 2>&1; then
  echo 'CANDIDATE_BLOCKED restore; private server log retained' >&2
  exit 4
fi

psql_candidate() {
  docker exec "$DB_CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d mskba_candidate -c "$1"
}
critical_tables="users profiles contacts actors teams tournaments games events venues venue_courts event_participants game_player_statistics contracts contract_memberships"
for table in $critical_tables; do
  case "$table" in *[!a-z_]*|'') exit 5;; esac
  echo "$table|$(psql_candidate "SELECT count(*), md5(COALESCE(string_agg(id::text,',' ORDER BY id),'')) FROM public.$table")" >> "$DEST/before.txt"
done
psql_candidate "SELECT md5(COALESCE(string_agg(id::text || ':' || COALESCE(password,''), ',' ORDER BY id),'')) FROM users" > "$DEST/credential-fingerprint-before.txt"
psql_candidate "SELECT count(*) FROM migrations" > "$DEST/migrations-before.txt"

# This container can reach ONLY the temporary candidate DB.
# Environment overrides any dev .env and isolates session/mail/queue/realtime.
if ! docker run --rm --network "$NET" --workdir /var/www/html \
  -v "$DEV_ROOT:/var/www/html:ro" \
  -v "$DEST/storage:/var/www/html/storage:rw" \
  -v "$DEST/bootstrap-cache:/var/www/html/bootstrap/cache:rw" \
  -e APP_ENV=testing -e APP_DEBUG=false -e APP_KEY="$APP_SECRET" \
  -e APP_URL=http://candidate.invalid -e APP_TIMEZONE=Europe/Moscow \
  -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=candidate-db \
  -e DB_PORT=5432 -e DB_DATABASE=mskba_candidate -e DB_USERNAME=postgres \
  -e DB_PASSWORD="$DB_PASS" -e DB_TIMEZONE=Europe/Moscow \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=database \
  -e BROADCAST_CONNECTION=log -e MAIL_MAILER=log \
  -e LOG_CHANNEL=single -e TELEGRAM_UPDATES_TRANSPORT=disabled \
  -e FEATURE_VENUE_RENTAL_BOOKING_EVENTS=false \
  --entrypoint php mskbanew-phpfpm:latest artisan migrate --force --no-interaction \
  > "$DEST/migrate.log" 2>&1; then
  echo 'CANDIDATE_BLOCKED dev migrations failed; private migrate.log retained' >&2
  exit 6
fi

for table in $critical_tables; do
  echo "$table|$(psql_candidate "SELECT count(*), md5(COALESCE(string_agg(id::text,',' ORDER BY id),'')) FROM public.$table")" >> "$DEST/after.txt"
done
psql_candidate "SELECT md5(COALESCE(string_agg(id::text || ':' || COALESCE(password,''), ',' ORDER BY id),'')) FROM users" > "$DEST/credential-fingerprint-after.txt"
psql_candidate "SELECT count(*) FROM migrations" > "$DEST/migrations-after.txt"

# Existing IDs for core historical entities and all stored password hashes must survive.
if ! diff -q "$DEST/credential-fingerprint-before.txt" "$DEST/credential-fingerprint-after.txt" >/dev/null; then
  echo 'CANDIDATE_BLOCKED credentials changed' >&2
  exit 7
fi
for table in users profiles contacts actors teams tournaments games events venues venue_courts event_participants game_player_statistics; do
  before="$(grep "^$table|" "$DEST/before.txt")"
  after="$(grep "^$table|" "$DEST/after.txt")"
  if [[ "$before" != "$after" ]]; then
    echo "CANDIDATE_BLOCKED historical ID set changed: $table" >&2
    exit 8
  fi
done

# Allow new contract/backfill records but not deletion of old contracts.
for table in contracts contract_memberships; do
  old="$(grep "^$table|" "$DEST/before.txt" | cut -d '|' -f 2)"
  new="$(grep "^$table|" "$DEST/after.txt" | cut -d '|' -f 2)"
  [[ "$new" -ge "$old" ]] || { echo "CANDIDATE_BLOCKED $table count decreased" >&2; exit 9; }
done

ledger="$(cat "$DEST/migrations-after.txt")"
expected_files="$(find "$DEV_ROOT/database/migrations" -maxdepth 1 -type f -name '*.php' | wc -l | tr -d '[:space:]')"
expected_ledger="$((expected_files + 1))"
if [[ "$ledger" -ne "$expected_ledger" ]]; then
  echo "CANDIDATE_BLOCKED migration ledger count $ledger expected $expected_ledger" >&2
  exit 10
fi
invalid_fks="$(psql_candidate "SELECT count(*) FROM pg_constraint WHERE contype = 'f' AND NOT convalidated")"
[[ "$invalid_fks" == 0 ]] || { echo 'CANDIDATE_BLOCKED unvalidated foreign keys' >&2; exit 11; }

# Protected checkpoint for the NEXT phase; it is not a production database.
if ! docker exec "$DB_CONTAINER" pg_dump -U postgres -d mskba_candidate \
  --format=custom --no-owner --no-acl > "$DEST/candidate-migrated.pgdump.part"; then
  echo 'CANDIDATE_BLOCKED failed to capture migrated copy' >&2
  exit 12
fi
test -s "$DEST/candidate-migrated.pgdump.part"
mv "$DEST/candidate-migrated.pgdump.part" "$DEST/candidate-migrated.pgdump"
sha256sum "$DEST/candidate-migrated.pgdump" > "$DEST/candidate-sha256.txt"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +

echo 'CANDIDATE_SCHEMA_PASS=true'
echo "dev_source_commit=$(git -C "$DEV_ROOT" rev-parse --short HEAD)"
echo "prod_snapshot_migrations=$(cat "$DEST/migrations-before.txt")"
echo "candidate_migrations=$ledger"
echo "dev_migration_file_count=$expected_files"
echo "candidate_invalid_foreign_keys=$invalid_fks"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/candidate-migrated.pgdump")"
echo "private_candidate_path=$DEST"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
