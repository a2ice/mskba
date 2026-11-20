#!/usr/bin/env bash
# Task 325: private identity conflict quarantine, not a live account merger.
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
ID="$1"; PARENT="$2"; SCRIPT="$3"
[[ "$SCRIPT" == "/home/deploy/mskba-data-merge-tools/$ID/scripts/data-merge/quarantine-unmatched-identities.php" ]] || exit 2
BASE=/home/deploy/mskba-data-merge-candidates
SOURCE="$BASE/$PARENT-shop-import/shop-candidate.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
DEST="$BASE/$ID-identity-quarantine"
DB="mskba-identity-quarantine-$ID"
NET="mskba-quarantine-net-$ID"
test -s "$SOURCE"
test -r "$SCRIPT"
test ! -e "$DEST"
( cd "$BASE/$PARENT-shop-import" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST";chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup() { docker stop "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db --name "$DB" \
 -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
 if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
    docker exec "$DB" pg_isready -q -U postgres -d candidate; then ready=true;break;fi
 sleep 1
done
[[ "$ready" == true ]] || exit 3
docker exec -i "$DB" pg_restore -U postgres -d candidate --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/candidate-restore.log" 2>&1 || { echo 'QUARANTINE_BLOCKED candidate restore';exit 4; }
docker exec "$DB" createdb -U postgres dev_snapshot
docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/dev-restore.log" 2>&1 || { echo 'QUARANTINE_BLOCKED dev restore';exit 5; }
sql() { docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
before_hash="$(sql "SELECT md5(string_agg(id::text || ':' || coalesce(password,''), ',' ORDER BY id)) FROM users")"
before_users="$(sql 'SELECT count(*) FROM users')"
run_import(){
 docker run --rm --network "$NET" -v "$SCRIPT:/tmp/quarantine.php:ro" \
 -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
 -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
 --entrypoint php mskbanew-phpfpm:latest /tmp/quarantine.php "$@"
}
run_import > "$DEST/dry-run.log" 2>&1 || { echo 'QUARANTINE_BLOCKED dry-run';exit 6; }
run_import --apply > "$DEST/apply.log" 2>&1 || { echo 'QUARANTINE_BLOCKED apply';exit 7; }
after_users="$(sql 'SELECT count(*) FROM users')"
[[ "$after_users" -eq "$((before_users + 1))" ]] || { echo 'QUARANTINE_BLOCKED account count mismatch';exit 8; }
[[ "$(sql "SELECT count(*) FROM data_merge_actor_map WHERE target_actor_id IS NULL")" -eq 0 ]] || { echo 'QUARANTINE_BLOCKED incomplete actor maps';exit 9; }
[[ "$(sql "SELECT count(*) FROM data_merge_user_map WHERE target_user_id IS NULL")" -eq 0 ]] || { echo 'QUARANTINE_BLOCKED incomplete user maps';exit 10; }
[[ "$before_hash" == "$(sql "SELECT md5(string_agg(id::text || ':' || coalesce(password,''), ',' ORDER BY id)) FROM users WHERE id NOT IN (SELECT target_user_id FROM data_merge_user_map WHERE resolution='quarantined')")" ]] || { echo 'QUARANTINE_BLOCKED existing credentials changed';exit 11; }
docker exec "$DB" pg_dump -U postgres -d candidate --format=custom --no-owner --no-acl > "$DEST/quarantined-candidate.pgdump.part"
test -s "$DEST/quarantined-candidate.pgdump.part"
mv "$DEST/quarantined-candidate.pgdump.part" "$DEST/quarantined-candidate.pgdump"
sha256sum "$DEST/quarantined-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'QUARANTINE_REHEARSAL_COMPLETE=true'
echo "candidate_users=$after_users"
cat "$DEST/apply.log"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/quarantined-candidate.pgdump")"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
