#!/usr/bin/env bash
# Task 325: run media relation import against isolated post-quarantine snapshot.
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN="$1";PARENT="$2";SCRIPT="$3"
[[ "$SCRIPT" == "/home/deploy/mskba-data-merge-tools/$RUN/scripts/data-merge/import-dev-media-relations.php" ]] || exit 2
BASE=/home/deploy/mskba-data-merge-candidates
SOURCE="$BASE/$PARENT-identity-quarantine/quarantined-candidate.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
DEST="$BASE/$RUN-media-relations"
NET="mskba-media-net-$RUN";DB="mskba-media-db-$RUN"
test ! -e "$DEST"
test -r "$SCRIPT"
test -s "$SOURCE"
( cd "$BASE/$PARENT-identity-quarantine" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST";chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup(){ docker stop "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db --name "$DB" \
 -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
 if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
    docker exec "$DB" pg_isready -q -U postgres -d candidate;then ready=true;break;fi
 sleep 1
done
[[ "$ready" == true ]] || exit 3
docker exec -i "$DB" pg_restore -U postgres -d candidate --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/candidate-restore.log" 2>&1 || { echo 'MEDIA_RELATIONS_BLOCKED candidate restore';exit 4; }
docker exec "$DB" createdb -U postgres dev_snapshot
docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/dev-restore.log" 2>&1 || { echo 'MEDIA_RELATIONS_BLOCKED dev restore';exit 5; }
sql(){ docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
before_count="$(sql 'SELECT count(*) FROM media')"
max_before="$(sql 'SELECT coalesce(max(id),0) FROM media')"
before_hash="$(sql "SELECT md5(coalesce(string_agg(id||':'||mediable_type||':'||mediable_id||':'||path||':'||is_featured,',' ORDER BY id),'')) FROM media")"
run_import(){
 docker run --rm --network "$NET" -v "$SCRIPT:/tmp/import-media.php:ro" \
 -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
 -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
 --entrypoint php mskbanew-phpfpm:latest /tmp/import-media.php "$@"
}
run_import > "$DEST/dry-run.log" 2>&1 || { echo 'MEDIA_RELATIONS_BLOCKED dry-run';exit 6; }
run_import --apply > "$DEST/apply.log" 2>&1 || { echo 'MEDIA_RELATIONS_BLOCKED apply';exit 7; }
[[ "$(sql 'SELECT count(*) FROM data_merge_media_mappings WHERE kind='\''media'\''')" -eq 109 ]] || { echo 'MEDIA_RELATIONS_BLOCKED incomplete image map';exit 8; }
[[ "$(sql 'SELECT count(*) FROM data_merge_media_mappings WHERE kind='\''site_media_asset'\''')" -eq 45 ]] || { echo 'MEDIA_RELATIONS_BLOCKED incomplete media library';exit 9; }
[[ "$before_hash" == "$(sql "SELECT md5(coalesce(string_agg(id||':'||mediable_type||':'||mediable_id||':'||path||':'||is_featured,',' ORDER BY id),'')) FROM media WHERE id <= $max_before")" ]] || { echo 'MEDIA_RELATIONS_BLOCKED original production media changed';exit 10; }
[[ "$(sql "SELECT count(*) FROM pg_constraint WHERE contype='f' AND NOT convalidated")" -eq 0 ]] || { echo 'MEDIA_RELATIONS_BLOCKED unvalidated foreign key';exit 11; }
docker exec "$DB" pg_dump -U postgres -d candidate --format=custom --no-owner --no-acl > "$DEST/media-candidate.pgdump.part"
test -s "$DEST/media-candidate.pgdump.part"
mv "$DEST/media-candidate.pgdump.part" "$DEST/media-candidate.pgdump"
sha256sum "$DEST/media-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'MEDIA_RELATIONS_REHEARSAL_COMPLETE=true'
echo "media_before=$before_count"
echo "media_after=$(sql 'SELECT count(*) FROM media')"
cat "$DEST/apply.log"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/media-candidate.pgdump")"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
