#!/usr/bin/env bash
# Task 325: last offline data reconciliation against an isolated PostgreSQL 17.
# NEVER connects to the live production or development database.
set -euo pipefail
umask 077
[[ $# -eq 2 && "$1" =~ ^[0-9]+$ ]] || exit 2
RUN="$1"; STAGE="$2"
[[ "$STAGE" == "/home/deploy/mskba-data-merge-tools/$RUN/scripts/data-merge" ]] || exit 2
for file in quarantine-unmatched-identities.php import-dev-media-relations.php import-dev-content.php; do
  test -r "$STAGE/$file"
done
BASE=/home/deploy/mskba-data-merge-candidates
PARENT=44032940142
SOURCE="$BASE/$PARENT-shop-import/shop-candidate.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
MEDIA_UNION="$BASE/44032038961-media-union"
DEST="$BASE/$RUN-finalized"
NET="mskba-final-net-$RUN"
DB="mskba-final-db-$RUN"
test ! -e "$DEST"
test -s "$SOURCE"
test -s "$DEV"
( cd "$BASE/$PARENT-shop-import" && sha256sum --status -c SHA256SUMS )
( cd "$MEDIA_UNION" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST"
chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup() { docker stop "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
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
[[ "$ready" == true ]] || { echo 'FINALIZED_BLOCKED isolated postgres start';exit 3; }
if ! docker exec -i "$DB" pg_restore -U postgres -d candidate \
  --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/candidate-restore.log" 2>&1; then
 echo 'FINALIZED_BLOCKED candidate restore'; exit 4
fi
docker exec "$DB" createdb -U postgres dev_snapshot
if ! docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot \
 --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/dev-restore.log" 2>&1; then
 echo 'FINALIZED_BLOCKED dev restore'; exit 5
fi
sql(){ docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
original_users="$(sql "SELECT md5(coalesce(string_agg(id||':'||coalesce(password,''),',' ORDER BY id),'')) FROM users")"
original_venues="$(sql "SELECT md5(coalesce(string_agg(id||':'||alias||':'||status,',' ORDER BY id),'')) FROM venues")"
original_bookings="$(sql 'SELECT count(*) FROM venue_bookings')"
run_stage(){
 local name="$1" file="$2"
 docker run --rm --network "$NET" \
  -v "$STAGE/$file:/tmp/task.php:ro" \
  -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
  -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
  --entrypoint php mskbanew-phpfpm:latest /tmp/task.php > "$DEST/$name-dry-run.log" 2>&1 || {
   echo "FINALIZED_BLOCKED $name dry-run";exit 6;
  }
 docker run --rm --network "$NET" \
  -v "$STAGE/$file:/tmp/task.php:ro" \
  -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
  -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
  --entrypoint php mskbanew-phpfpm:latest /tmp/task.php --apply > "$DEST/$name-apply.log" 2>&1 || {
   echo "FINALIZED_BLOCKED $name apply";exit 7;
  }
}
run_stage quarantine quarantine-unmatched-identities.php
run_stage media import-dev-media-relations.php
run_stage content import-dev-content.php

[[ "$(sql 'SELECT count(*) FROM users')" -eq 55 ]] || { echo 'FINALIZED_BLOCKED expected quarantined identity'; exit 8; }
[[ "$(sql 'SELECT count(*) FROM data_merge_actor_map WHERE target_actor_id IS NULL')" -eq 0 ]] || { echo 'FINALIZED_BLOCKED unresolved actor';exit 9; }
[[ "$(sql "SELECT count(*) FROM data_merge_media_mappings WHERE kind='media'")" -eq 109 ]] || { echo 'FINALIZED_BLOCKED incomplete media mapping';exit 10; }
[[ "$(sql "SELECT count(*) FROM data_merge_media_mappings WHERE kind='site_media_asset'")" -eq 45 ]] || { echo 'FINALIZED_BLOCKED incomplete media library';exit 11; }
[[ "$(sql 'SELECT count(*) FROM data_merge_content_map')" -eq 12 ]] || { echo 'FINALIZED_BLOCKED incomplete content mapping';exit 12; }
[[ "$(sql 'SELECT count(*) FROM venue_bookings')" == "$original_bookings" ]] || { echo 'FINALIZED_BLOCKED production bookings changed';exit 13; }
[[ "$(sql "SELECT md5(coalesce(string_agg(id||':'||alias||':'||status,',' ORDER BY id),'')) FROM venues")" == "$original_venues" ]] || { echo 'FINALIZED_BLOCKED venues changed';exit 14; }
[[ "$(sql "SELECT md5(coalesce(string_agg(id||':'||coalesce(password,''),',' ORDER BY id),'')) FROM users WHERE id NOT IN (SELECT target_user_id FROM data_merge_user_map WHERE resolution='quarantined')")" == "$original_users" ]] || {
 echo 'FINALIZED_BLOCKED original account/password changed';exit 15;
}
[[ "$(sql "SELECT count(*) FROM pg_constraint WHERE contype='f' AND NOT convalidated")" -eq 0 ]] || { echo 'FINALIZED_BLOCKED invalid FK';exit 16; }

# Output only aggregate statistics. Every mapping, PII value and SQL dump
# stays private on the server.
if ! docker exec "$DB" pg_dump -U postgres -d candidate --format=custom \
  --no-owner --no-acl > "$DEST/finalized-candidate.pgdump.part"; then
 echo 'FINALIZED_BLOCKED pg_dump';exit 17
fi
test -s "$DEST/finalized-candidate.pgdump.part"
mv "$DEST/finalized-candidate.pgdump.part" "$DEST/finalized-candidate.pgdump"
sha256sum "$DEST/finalized-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'FINALIZED_REHEARSAL_COMPLETE=true'
echo "candidate_users=$(sql 'SELECT count(*) FROM users')"
echo "candidate_venues=$(sql 'SELECT count(*) FROM venues')"
echo "candidate_courts=$(sql 'SELECT count(*) FROM venue_courts')"
echo "candidate_bookings=$(sql 'SELECT count(*) FROM venue_bookings')"
echo "candidate_media=$(sql 'SELECT count(*) FROM media')"
echo "candidate_site_media_assets=$(sql 'SELECT count(*) FROM site_media_assets')"
echo "candidate_content_items=$(sql 'SELECT count(*) FROM content_items')"
echo "candidate_leagues=$(sql 'SELECT count(*) FROM leagues')"
echo "candidate_teams=$(sql 'SELECT count(*) FROM teams')"
echo "candidate_unresolved_related_links=$(sql 'SELECT count(*) FROM data_merge_content_unresolved_related')"
for name in quarantine media content; do
  grep -E '^(quarantined_accounts=|resolved_pending_actor_mappings=|source_media_count=|mapped_site_media_assets=|mapped_leagues=|mapped_teams=|new_content_items=|prod_aliases_preserved=|item_tag_links_copied=|faq_links_copied=|unresolved_related_links=)' "$DEST/$name-apply.log" || true
done
echo "private_checkpoint_path=$DEST"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
