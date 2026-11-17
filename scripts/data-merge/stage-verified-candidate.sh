#!/usr/bin/env bash
# Task 325: stage approved merged DB in an unused production PostgreSQL DB.
# No changes to live application, original DB or production file store.
set -euo pipefail
umask 077
[[ $# -eq 2 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[a-z][a-z0-9_]{5,55}$ ]] || {
 echo 'Usage: stage-verified-candidate.sh <approved-run-id> <new-db-name>' >&2
 exit 2
}
RUN="$1"; NEW_DB="$2"
BASE=/home/deploy/mskba-data-merge-candidates
DIR="$BASE/$RUN-web-smoke"
SOURCE="$DIR/web-approved-candidate.pgdump"
test -s "$SOURCE" && test -f "$DIR/SHA256SUMS"
( cd "$DIR" && sha256sum --status -c SHA256SUMS )
cd /var/www/mskba
test -f .env
grep -Fxq 'APP_URL=https://mskba.ru' .env
grep -Fxq 'APP_ENV=production' .env
OLD_DB="$(sed -n 's/^DB_DATABASE=//p' .env | tail -n1)"
[[ "$OLD_DB" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ && "$OLD_DB" != "$NEW_DB" ]] || {
 echo 'STAGE_BLOCKED invalid or unchanged DB name' >&2; exit 3
}
COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml"
AVAILABLE="$($COMPOSE exec -T db sh -ceu 'export PGPASSWORD="$POSTGRES_PASSWORD"; psql -XAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT datname FROM pg_database"')"
if printf '%s\n' "$AVAILABLE" | grep -Fxq "$NEW_DB"; then
 echo 'STAGE_BLOCKED new database already exists' >&2; exit 4
fi
FREE_KB="$(df -Pk . | awk 'END {print $4}')"
[[ "$FREE_KB" -ge 1048576 ]] || {
 echo 'STAGE_BLOCKED less than 1 GiB disk space' >&2; exit 5
}
# New DB is deliberately separate from the live production database.
$COMPOSE exec -T db sh -ceu 'export PGPASSWORD="$POSTGRES_PASSWORD"; createdb -U "$POSTGRES_USER" "$1"' sh "$NEW_DB"
if ! $COMPOSE exec -T db sh -ceu \
 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_restore -U "$POSTGRES_USER" -d "$1" --format=custom --no-owner --no-acl --exit-on-error' sh "$NEW_DB" \
 < "$SOURCE" > "$DIR/production-stage-restore.log" 2>&1; then
 echo 'STAGE_BLOCKED candidate restore; live DB still active' >&2; exit 6
fi
query() {
 $COMPOSE exec -T db sh -ceu \
  'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -XAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "$2"' sh "$NEW_DB" "$1"
}
for gate in 'users|55' 'venues|18' 'venue_courts|24' 'venue_bookings|23' 'media|204' 'content_items|19' 'teams|13' 'leagues|1'; do
 table="$(printf '%s\n' "$gate" | cut -d '|' -f1)"
 expected="$(printf '%s\n' "$gate" | cut -d '|' -f2)"
 actual="$(query "SELECT count(*) FROM public.$table")"
 [[ "$actual" == "$expected" ]] || {
  echo "STAGE_BLOCKED $table count mismatch" >&2; exit 7
 }
done
LEDGER="$(query 'SELECT count(*) FROM migrations')"
[[ "$LEDGER" == 286 ]] || { echo 'STAGE_BLOCKED migration ledger mismatch' >&2; exit 8; }
{
 printf 'old_db=%s\n' "$OLD_DB"
 printf 'staged_db=%s\n' "$NEW_DB"
 printf 'source_run=%s\n' "$RUN"
 printf 'staged_at=%s\n' "$(date -u '+%FT%TZ')"
} > "$DIR/production-stage-info.txt"
chmod 600 "$DIR/production-stage-info.txt" "$DIR/production-stage-restore.log"
echo 'PRODUCTION_STAGING_PASS=true'
echo "staged_database=$NEW_DB"
echo "migration_ledger=$LEDGER"
echo 'ACTIVE_PRODUCTION_DATABASE_UNCHANGED=true'
