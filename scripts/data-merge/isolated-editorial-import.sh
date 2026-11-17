#!/usr/bin/env bash
# Task 325: isolated publication theme and Page Builder data import.
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN_ID="$1"; VENUE_RUN="$2"; STAGED_PHP="$3"
[[ "$STAGED_PHP" == "/home/deploy/mskba-data-merge-tools/$RUN_ID/scripts/data-merge/import-dev-editorial.php" ]] || exit 2
BASE=/home/deploy/mskba-data-merge-candidates
CANDIDATE="$BASE/$VENUE_RUN-venue-import/venue-candidate.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
DEST="$BASE/$RUN_ID-editorial-import"
NET="mskba-editorial-net-$RUN_ID"
DB="mskba-editorial-db-$RUN_ID"
test -s "$CANDIDATE"
test -s "$DEV"
test -r "$STAGED_PHP"
test ! -e "$DEST"
( cd "$BASE/$VENUE_RUN-venue-import" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST"
chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup() {
  docker stop "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db --name "$DB" \
  -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
  if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
     docker exec "$DB" pg_isready -q -U postgres -d candidate; then ready=true; break; fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'EDITORIAL_BLOCKED candidate start'; exit 3; }
if ! docker exec -i "$DB" pg_restore -U postgres -d candidate \
 --no-owner --no-acl --exit-on-error < "$CANDIDATE" > "$DEST/restore-target.log" 2>&1; then
 echo 'EDITORIAL_BLOCKED candidate restore'; exit 4
fi
docker exec "$DB" createdb -U postgres dev_snapshot
if ! docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot \
 --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/restore-dev.log" 2>&1; then
 echo 'EDITORIAL_BLOCKED dev restore'; exit 5
fi
sql() { docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
users_before="$(sql 'SELECT count(*) FROM users')"
venues_before="$(sql 'SELECT count(*) FROM venues')"
bookings_before="$(sql 'SELECT count(*) FROM venue_bookings')"
run_import() {
  docker run --rm --network "$NET" -v "$STAGED_PHP:/tmp/editorial.php:ro" \
    -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
    -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
    --entrypoint php mskbanew-phpfpm:latest /tmp/editorial.php "$@"
}
if ! run_import > "$DEST/dry-run.log" 2>&1; then
  echo 'EDITORIAL_BLOCKED dry-run; private log retained'; exit 6
fi
if ! run_import --apply > "$DEST/apply.log" 2>&1; then
  echo 'EDITORIAL_BLOCKED apply; private log retained'; exit 7
fi
[[ "$users_before" == "$(sql 'SELECT count(*) FROM users')" ]] || { echo 'EDITORIAL_BLOCKED user count changed'; exit 8; }
[[ "$venues_before" == "$(sql 'SELECT count(*) FROM venues')" ]] || { echo 'EDITORIAL_BLOCKED venue count changed'; exit 9; }
[[ "$bookings_before" == "$(sql 'SELECT count(*) FROM venue_bookings')" ]] || { echo 'EDITORIAL_BLOCKED booking count changed'; exit 10; }
[[ "$(sql 'SELECT count(*) FROM page_builder_components')" -ge 8 ]] || { echo 'EDITORIAL_BLOCKED missing dev components'; exit 11; }
[[ "$(sql 'SELECT count(*) FROM page_builder_revisions')" -ge 575 ]] || { echo 'EDITORIAL_BLOCKED missing dev revisions'; exit 12; }
[[ "$(sql 'SELECT count(*) FROM design_theme_code_revisions')" -ge 76 ]] || { echo 'EDITORIAL_BLOCKED missing theme history'; exit 13; }
if ! docker exec "$DB" pg_dump -U postgres -d candidate --format=custom \
 --no-owner --no-acl > "$DEST/editorial-candidate.pgdump.part"; then
 echo 'EDITORIAL_BLOCKED checkpoint'; exit 14
fi
test -s "$DEST/editorial-candidate.pgdump.part"
mv "$DEST/editorial-candidate.pgdump.part" "$DEST/editorial-candidate.pgdump"
sha256sum "$DEST/editorial-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'EDITORIAL_REHEARSAL_COMPLETE=true'
echo "candidate_user_count=$users_before"
echo "candidate_venue_count=$venues_before"
echo "candidate_booking_count=$bookings_before"
cat "$DEST/apply.log"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/editorial-candidate.pgdump")"
echo "private_checkpoint_path=$DEST"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
