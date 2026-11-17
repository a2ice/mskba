#!/usr/bin/env bash
# Task 325. Production freeze and reversible preparation for authorized cutover.
# The original database is NEVER modified, dropped or renamed.
set -euo pipefail
umask 077
ROOT=/var/www/mskba
STAGED_DB=mskba_unified_20261117_v2
OLD_SHA=cc33a60b7791d1038ca1db0417c1be14aae7a651
DIR=/home/deploy/mskba-cutover-backups/release-20261117
MEDIA=/home/deploy/mskba-data-merge-candidates/44032038961-media-union/storage-app-combined.tar.gz
cd "$ROOT"
test -f .env
grep -Fxq 'APP_ENV=production' .env
grep -Fxq 'APP_URL=https://mskba.ru' .env
test "$(git rev-parse HEAD)" = "$OLD_SHA"
test -s "$MEDIA"
( cd "$(dirname "$MEDIA")" && sha256sum -c --status SHA256SUMS )
test ! -e "$DIR"
[[ "$(grep -c '^DB_DATABASE=' .env)" -eq 1 ]]
OLD_DB="$(grep '^DB_DATABASE=' .env | cut -d= -f2-)"
[[ "$OLD_DB" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ && "$OLD_DB" != "$STAGED_DB" ]]
test -n "$(grep '^APP_KEY=.' .env)"
COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml"
query() {
 $COMPOSE exec -T db sh -ceu \
  'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -XAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "$2"' sh "$1" "$2"
}
for spec in 'users|46|55' 'profiles|46|55' 'teams|13|13' 'tournaments|2|2' 'games|12|12' 'venues|14|18' 'venue_courts|17|24' 'venue_bookings|23|23';do
 IFS='|' read -r table before after <<< "$spec"
 [[ "$(query "$OLD_DB" "SELECT count(*) FROM $table")" == "$before" ]] || { echo "CUTOVER_BLOCKED source $table drift";exit 3; }
 [[ "$(query "$STAGED_DB" "SELECT count(*) FROM $table")" == "$after" ]] || { echo "CUTOVER_BLOCKED candidate $table mismatch";exit 4; }
done
[[ "$(query "$STAGED_DB" 'SELECT count(*) FROM migrations')" == 286 ]] || exit 5
[[ "$(query "$STAGED_DB" 'SELECT count(*) FROM data_merge_actor_map WHERE target_actor_id IS NULL')" == 0 ]] || exit 6
[[ "$(df -Pm "$ROOT" | awk 'END{print $4}')" -ge 2048 ]] || exit 7
mkdir -p "$(dirname "$DIR")"
mkdir "$DIR"
chmod 700 "$DIR"
cp -p .env "$DIR/original.env"
printf '%s\n' "$OLD_DB" > "$DIR/original-db-name"
printf '%s\n' "$OLD_SHA" > "$DIR/original-git-sha"
$COMPOSE ps --status running --services > "$DIR/running-services-before"
FROZEN=0
PREPARED=0
rollback() {
 if [[ "$PREPARED" -eq 0 && "$FROZEN" -eq 1 ]];then
  echo 'CUTOVER_PREP_FAILED_RESTORING_OLD_SITE=true'
  cp -p "$DIR/original.env" "$ROOT/.env" || true
  $COMPOSE exec -T phpfpm php artisan up >/dev/null 2>&1 || true
  $COMPOSE up -d queue telegram-inbound-queue telegram-background-queue >/dev/null 2>&1 || true
  if grep -Fxq telegram "$DIR/running-services-before";then
   $COMPOSE --profile polling up -d telegram >/dev/null 2>&1 || true
  fi
 fi
}
trap rollback EXIT
$COMPOSE exec -T phpfpm php artisan down --retry=120 >/dev/null
FROZEN=1
$COMPOSE stop queue telegram-inbound-queue telegram-background-queue >/dev/null
if grep -Fxq telegram "$DIR/running-services-before";then
 $COMPOSE --profile polling stop telegram >/dev/null
fi
# Fresh immutable snapshot made AFTER freeze, before any DB or media switch.
$COMPOSE exec -T db sh -ceu \
 'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_dump -U "$POSTGRES_USER" -d "$1" --format=custom --no-owner --no-acl' sh "$OLD_DB" > "$DIR/original-production.pgdump.part"
test -s "$DIR/original-production.pgdump.part"
mv "$DIR/original-production.pgdump.part" "$DIR/original-production.pgdump"
tar -czf "$DIR/original-storage.tar.gz.part" -C "$ROOT" storage/app
test -s "$DIR/original-storage.tar.gz.part"
mv "$DIR/original-storage.tar.gz.part" "$DIR/original-storage.tar.gz"
test -f public/build/manifest.json
tar -czf "$DIR/original-build.tar.gz.part" -C "$ROOT" public/build
test -s "$DIR/original-build.tar.gz.part"
mv "$DIR/original-build.tar.gz.part" "$DIR/original-build.tar.gz"
sha256sum "$DIR/original-production.pgdump" "$DIR/original-storage.tar.gz" "$DIR/original-build.tar.gz" "$DIR/original.env" > "$DIR/SHA256SUMS"
sha256sum -c --status "$DIR/SHA256SUMS"
echo 'CUTOVER_ORIGINAL_DB_AND_MEDIA_BACKED_UP=true'
# Validate existing paths and add only genuinely NEW files; never overwrite.
python3 - "$MEDIA" "$ROOT" <<'PY'
import hashlib,os,pathlib,sys,tarfile,tempfile
source=pathlib.Path(sys.argv[1]);root=pathlib.Path(sys.argv[2])
entries=[]
with tarfile.open(source,'r:gz') as arc:
 for entry in arc:
  if entry.isdir():continue
  if not entry.isfile():raise SystemExit('CUTOVER_BLOCKED non-file archive member')
  parts=pathlib.PurePosixPath(entry.name).parts
  if parts[:2]!=('storage','app') or '..' in parts:raise SystemExit('CUTOVER_BLOCKED media path')
  destination=root.joinpath(*parts)
  if destination.is_symlink():raise SystemExit('CUTOVER_BLOCKED media symlink')
  if destination.exists():
   a=hashlib.sha256(destination.read_bytes()).digest()
   b=hashlib.sha256(arc.extractfile(entry).read()).digest()
   if a!=b:raise SystemExit('CUTOVER_BLOCKED production media collision')
  entries.append((entry,destination))
created=0
with tarfile.open(source,'r:gz') as arc:
 for entry,destination in entries:
  if destination.exists():continue
  destination.parent.mkdir(parents=True,exist_ok=True)
  with tempfile.NamedTemporaryFile(dir=destination.parent,delete=False) as tmp:
   path=tmp.name
   stream=arc.extractfile(entry)
   while True:
    chunk=stream.read(1024*1024)
    if not chunk:break
    tmp.write(chunk)
  os.chmod(path,0o644)
  try:os.link(path,destination)
  except FileExistsError:pass
  finally:os.unlink(path)
  created+=1
print('CUTOVER_MEDIA_FILES_CHECKED='+str(len(entries)))
print('CUTOVER_NEW_MEDIA_FILES='+str(created))
PY
sed -i "s/^DB_DATABASE=.*/DB_DATABASE=$STAGED_DB/" .env
[[ "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" == "$STAGED_DB" ]]
cmp -s <(grep '^APP_KEY=' "$DIR/original.env") <(grep '^APP_KEY=' .env)
{
 printf 'CUTOVER_PREPARED=true\n'
 printf 'original_db=%s\n' "$OLD_DB"
 printf 'new_db=%s\n' "$STAGED_DB"
 printf 'old_commit=%s\n' "$OLD_SHA"
 printf 'prepared_utc=%s\n' "$(date -u +%FT%TZ)"
} > "$DIR/CUTOVER_STATE"
chmod 600 "$DIR"/*
PREPARED=1
echo 'CUTOVER_PREPARED=true'
echo "staged_db=$STAGED_DB"
echo 'OLD_PRODUCTION_DATABASE_INTACT=true'
echo 'PRODUCTION_IN_MAINTENANCE_AWAITING_NEW_CODE=true'
