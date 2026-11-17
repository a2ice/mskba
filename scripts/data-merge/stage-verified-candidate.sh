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
# The offline shop import used postgres_fdw for a one-time copy.
# Its dev_snapshot server, foreign tables and USER MAPPING to the isolated
# container's 'postgres' role must NOT be restored on production. They are
# *not* application data; the copied public.shop_* tables are preserved.
TOC="$DIR/production-stage-restore-$NEW_DB.list"
$COMPOSE exec -T db pg_restore --list < "$SOURCE" > "$TOC"
python3 - "$TOC" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
lines = path.read_text().splitlines(keepends=True)
def ephemeral(line):
    if line.startswith(';') or not line.strip():
        return False
    kinds = (
        ' SCHEMA - ext_dev_shop ',
        ' SERVER - dev_shop_snapshot ',
        ' USER MAPPING ',
        ' FOREIGN TABLE ext_dev_shop ',
        ' FOREIGN TABLE DATA ext_dev_shop ',
        ' TABLE DATA ext_dev_shop ',
        ' EXTENSION - postgres_fdw ',
        ' COMMENT - EXTENSION postgres_fdw ',
        ' COMMENT - SERVER dev_shop_snapshot ',
        ' ACL - SCHEMA ext_dev_shop ',
    )
    return any(part in line for part in kinds)
removed = [line for line in lines if ephemeral(line)]
if len(removed) < 12 or not any(' USER MAPPING ' in line for line in removed):
    raise SystemExit('STAGE_BLOCKED unexpected candidate TOC; no production writes')
remaining = [line for line in lines if not ephemeral(line)]
if any('ext_dev_shop' in line or 'dev_shop_snapshot' in line for line in remaining
       if not line.startswith(';')):
    raise SystemExit('STAGE_BLOCKED unexpected remaining external schema objects')
path.write_text(''.join(remaining))
print('STAGE_TEMP_FOREIGN_OBJECTS_EXCLUDED=' + str(len(removed)))
PY
chmod 600 "$TOC"
TOC_CONTAINER="/tmp/mskba-merge-$NEW_DB.list"
$COMPOSE exec -T db sh -ceu 'umask 077; cat > "$1"' sh "$TOC_CONTAINER" < "$TOC"
# New DB is deliberately separate from the live production database.
$COMPOSE exec -T db sh -ceu 'export PGPASSWORD="$POSTGRES_PASSWORD"; createdb -U "$POSTGRES_USER" "$1"' sh "$NEW_DB"
if ! $COMPOSE exec -T db sh -ceu \
 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_restore -L "$2" -U "$POSTGRES_USER" -d "$1" --format=custom --no-owner --no-acl --exit-on-error' sh "$NEW_DB" "$TOC_CONTAINER" \
 < "$SOURCE" > "$DIR/production-stage-restore.log" 2>&1; then
 echo 'STAGE_BLOCKED filtered candidate restore; live DB still active' >&2; exit 6
fi
$COMPOSE exec -T db sh -ceu 'rm -f "$1"' sh "$TOC_CONTAINER"
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
