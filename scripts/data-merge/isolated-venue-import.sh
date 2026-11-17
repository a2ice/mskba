#!/usr/bin/env bash
# Task 325: dev venue data imported into OFFLINE identity-upgraded candidate only.
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN_ID="$1"
IDENTITY_RUN="$2"
STAGED_PHP="$3"
[[ "$STAGED_PHP" == "/home/deploy/mskba-data-merge-tools/$RUN_ID/scripts/data-merge/import-dev-venues.php" ]] || exit 2
test -r "$STAGED_PHP"

BASE=/home/deploy/mskba-data-merge-candidates
DEST="$BASE/$RUN_ID-venue-import"
CANDIDATE="$BASE/$IDENTITY_RUN-identity-import/identity-candidate.pgdump"
SOURCE=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
NET="mskba-venue-net-$RUN_ID"
DB="mskba-venue-db-$RUN_ID"
test ! -e "$DEST"
test -s "$CANDIDATE"
test -s "$SOURCE"
(
  cd "$BASE/$IDENTITY_RUN-identity-import"
  sha256sum -c --status SHA256SUMS
)
mkdir -p "$DEST"
chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup() {
  docker stop "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$NET" > /dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db --name "$DB" \
  -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
  if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' \
     && docker exec "$DB" pg_isready -q -U postgres -d candidate; then
    ready=true; break
  fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'VENUE_IMPORT_BLOCKED candidate start'; exit 3; }
if ! docker exec -i "$DB" pg_restore -U postgres -d candidate \
 --no-owner --no-acl --exit-on-error < "$CANDIDATE" > "$DEST/candidate-restore.log" 2>&1; then
 echo 'VENUE_IMPORT_BLOCKED candidate restore'; exit 4
fi
docker exec "$DB" createdb -U postgres dev_snapshot
if ! docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot \
 --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/dev-restore.log" 2>&1; then
 echo 'VENUE_IMPORT_BLOCKED dev restore'; exit 5
fi
query() { docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
query "SELECT md5(coalesce(string_agg(id||':'||alias||':'||status||':'||coalesce(created_by_actor_id::text,''),',' ORDER BY id),'')) FROM venues" > "$DEST/venue-original-fingerprint"
query "SELECT md5(coalesce(string_agg(id||':'||alias||':'||venue_id,',' ORDER BY id),'')) FROM venue_courts" > "$DEST/court-original-fingerprint"
query "SELECT count(*) FROM venue_bookings" > "$DEST/booking-count-before"
query "SELECT count(*) FROM venues" > "$DEST/venue-count-before"
query "SELECT count(*) FROM venue_courts" > "$DEST/court-count-before"

run_import() {
  docker run --rm --network "$NET" -v "$STAGED_PHP:/tmp/merge-venues.php:ro" \
    -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
    -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
    --entrypoint php mskbanew-phpfpm:latest /tmp/merge-venues.php "$@"
}
if ! run_import > "$DEST/dry-run.log" 2>&1; then
  echo 'VENUE_IMPORT_BLOCKED dry-run; inspect private log'; exit 6
fi
if ! run_import --apply > "$DEST/apply.log" 2>&1; then
  echo 'VENUE_IMPORT_BLOCKED apply; inspect private log'; exit 7
fi

before="$(cat "$DEST/venue-count-before")"
after="$(query 'SELECT COUNT(*) FROM venues')"
[[ "$before" == 14 && "$after" == 18 ]] || { echo 'VENUE_IMPORT_BLOCKED incorrect venue count'; exit 8; }
bookings="$(query 'SELECT COUNT(*) FROM venue_bookings')"
[[ "$bookings" == "$(cat "$DEST/booking-count-before")" ]] || { echo 'VENUE_IMPORT_BLOCKED booking history changed'; exit 9; }
# Compare the exact original prod ID/alias/status/owner tuples, excluding
# only newly inserted source venues/courts (do not assume contiguous IDs).
venue_after="$(query "SELECT md5(coalesce(string_agg(id||':'||alias||':'||status||':'||coalesce(created_by_actor_id::text,''),',' ORDER BY id),'')) FROM venues WHERE id NOT IN (SELECT target_venue_id FROM data_merge_venue_map WHERE resolution='new_venue')")"
court_after="$(query "SELECT md5(coalesce(string_agg(id||':'||alias||':'||venue_id,',' ORDER BY id),'')) FROM venue_courts WHERE id NOT IN (SELECT target_court_id FROM data_merge_court_map WHERE resolution='new_court')")"
[[ "$venue_after" == "$(cat "$DEST/venue-original-fingerprint")" ]] || { echo 'VENUE_IMPORT_BLOCKED historical venue identifiers/status/owner changed'; exit 10; }
[[ "$court_after" == "$(cat "$DEST/court-original-fingerprint")" ]] || { echo 'VENUE_IMPORT_BLOCKED historical court ID/alias/venue link changed'; exit 11; }
query 'SELECT source_venue_id,target_venue_id,resolution FROM data_merge_venue_map ORDER BY source_venue_id' > "$DEST/venue-map.tsv"
query 'SELECT source_court_id,target_court_id,resolution FROM data_merge_court_map ORDER BY source_court_id' > "$DEST/court-map.tsv"
[[ "$(query 'SELECT count(*) FROM data_merge_venue_map')" == 9 ]] || { echo 'VENUE_IMPORT_BLOCKED incomplete venue map'; exit 10; }
[[ "$(query 'SELECT count(*) FROM data_merge_court_map')" == 10 ]] || { echo 'VENUE_IMPORT_BLOCKED incomplete court map'; exit 11; }

if ! docker exec "$DB" pg_dump -U postgres -d candidate --format=custom \
 --no-owner --no-acl > "$DEST/venue-candidate.pgdump.part"; then
 echo 'VENUE_IMPORT_BLOCKED cannot create checkpoint'; exit 12
fi
test -s "$DEST/venue-candidate.pgdump.part"
mv "$DEST/venue-candidate.pgdump.part" "$DEST/venue-candidate.pgdump"
sha256sum "$DEST/venue-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'VENUE_REHEARSAL_COMPLETE=true'
echo "venue_count=$after"
echo "court_count=$(query 'SELECT COUNT(*) FROM venue_courts')"
cat "$DEST/apply.log"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/venue-candidate.pgdump")"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
