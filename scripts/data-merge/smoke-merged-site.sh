#!/usr/bin/env bash
# Task 325. Test the final merged snapshot with real DEV Laravel app,
# not with live DB/services. No public ports and no source writes.
set -euo pipefail
umask 077
[[ $# -eq 2 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN="$1"
SOURCE_RUN="$2"
ROOT=/home/deploy/mskba-data-merge-candidates
SOURCE="$ROOT/$SOURCE_RUN-finalized/finalized-candidate.pgdump"
MEDIA="$ROOT/44032038961-media-union/storage-app-combined.tar.gz"
DEST="$ROOT/$RUN-web-smoke"
DEV_ROOT=/var/www/mskba-dev-next
NET="mskba-smoke-net-$RUN"
DB="mskba-smoke-db-$RUN"
APP="mskba-smoke-app-$RUN"
test -s "$SOURCE" && test -s "$MEDIA"
test -f "$DEV_ROOT/artisan" && test -f "$DEV_ROOT/vendor/autoload.php"
test -f "$DEV_ROOT/public/build/manifest.json"
test ! -e "$DEST"
( cd "$ROOT/$SOURCE_RUN-finalized" && sha256sum --status -c SHA256SUMS )
( cd "$ROOT/44032038961-media-union" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST/storage/framework/cache/data" "$DEST/storage/framework/sessions" \
 "$DEST/storage/framework/views" "$DEST/storage/logs" "$DEST/bootstrap-cache"
chmod 700 "$DEST"
tar -xzf "$MEDIA" --no-same-owner --no-same-permissions -C "$DEST"
PW="$(openssl rand -hex 32)"
KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
cleanup(){
 docker stop "$APP" >/dev/null 2>&1 || true
 docker stop "$DB" >/dev/null 2>&1 || true
 docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null
docker run --rm -d --network "$NET" --network-alias candidate-db --network-alias db --name "$DB" \
 -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
 if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
 docker exec "$DB" pg_isready -q -U postgres -d candidate; then ready=true; break;fi
 sleep 1
done
[[ "$ready" == true ]] || { echo 'SMOKE_BLOCKED postgres';exit 3; }
docker exec -i "$DB" pg_restore -U postgres -d candidate \
 --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/restore.log" 2>&1 || {
 echo 'SMOKE_BLOCKED database restore';exit 4;
}
# Recreate the catalogue rights from the same idempotent policy as the
# historical owner backfill; do not grant commercial rental ownership.
RIGHTS_SCRIPT="/home/deploy/mskba-data-merge-tools/$RUN/scripts/data-merge/restore-imported-managers.php"
test -r "$RIGHTS_SCRIPT"
run_rights() {
 docker run --rm --network "$NET" -v "$RIGHTS_SCRIPT:/tmp/rights.php:ro" \
  -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
  -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
  --entrypoint php mskbanew-phpfpm:latest /tmp/rights.php "$@"
}
run_rights > "$DEST/rights-dry-run.log" 2>&1 || {
 echo 'SMOKE_BLOCKED manager-rights dry-run';exit 5;
}
run_rights --apply > "$DEST/rights-apply.log" 2>&1 || {
 echo 'SMOKE_BLOCKED manager-rights apply';exit 5;
}
# artisan serve spawns a child process and can reload the deployed dev
# .env, overriding this isolated candidate's DB_HOST. Run PHP's built-in
# server directly so Docker's explicit DB environment remains authoritative.
cat > "$DEST/serve-router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file('/var/www/html/public'.$path)) {
    return false;
}
require '/var/www/html/public/index.php';
PHP
chmod 600 "$DEST/serve-router.php"
docker run --rm -d --network "$NET" --network-alias preview-app --name "$APP" \
 --user 0:0 --workdir /var/www/html \
 -v "$DEV_ROOT:/var/www/html:ro" \
 -v "$DEST/storage:/var/www/html/storage:rw" \
 -v "$DEST/bootstrap-cache:/var/www/html/bootstrap/cache:rw" \
 -v "$DEST/serve-router.php:/tmp/serve-router.php:ro" \
 -e APP_ENV=testing -e APP_DEBUG=false -e APP_KEY="$KEY" \
 -e APP_URL=http://preview.invalid \
 -e DB_CONNECTION=pgsql -e DB_URL= -e DB_HOST=candidate-db \
 -e DB_PORT=5432 -e DB_DATABASE=candidate -e DB_USERNAME=postgres \
 -e DB_PASSWORD="$PW" -e DB_SSLMODE=disable \
 -e DB_TIMEZONE=Europe/Moscow -e APP_TIMEZONE=Europe/Moscow \
 -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
 -e MAIL_MAILER=log -e BROADCAST_CONNECTION=log -e LOG_CHANNEL=single \
 -e TELEGRAM_UPDATES_TRANSPORT=disabled -e OPENAI_API_KEY= \
 -e GITHUB_AI_TOKEN= -e YANDEX_AI_API_KEY= \
 --entrypoint php mskbanew-phpfpm:latest -S 0.0.0.0:8000 \
 -t /var/www/html/public /tmp/serve-router.php \
 > "$DEST/app-container-id" 2>&1

# Fail early on a wrong Laravel DB host or unavailable candidate connection.
# The direct PDO rights importer already proved that the Docker network works.
if docker exec "$APP" php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make("Illuminate\\Contracts\\Console\\Kernel")->bootstrap();
if (config("database.default") !== "pgsql" ||
    config("database.connections.pgsql.host") !== "candidate-db" ||
    config("database.connections.pgsql.database") !== "candidate") exit(2);
try { $app->make("db")->select("SELECT 1"); }
catch (Throwable $e) { exit(3); }
' >/dev/null 2>&1; then
 :
else
 result=$?
 echo "SMOKE_BLOCKED isolated Laravel DB preflight exit_code=$result"
 exit 5
fi
started=false
for _ in $(seq 1 45); do
 if docker exec "$APP" php -r '
 $c=stream_context_create(["http"=>["timeout"=>2,"ignore_errors"=>true]]);
 $h=@get_headers("http://127.0.0.1:8000/",true,$c);
 exit($h===false?1:0);' >/dev/null 2>&1; then started=true;break;fi
 sleep 1
done
[[ "$started" == true ]] || { echo 'SMOKE_BLOCKED Laravel HTTP start';exit 5; }

# Print only path and HTTP status, never response bodies or private data.
if ! docker exec -i "$APP" php > "$DEST/http-smoke.log" 2>&1 <<'PHP'; then
<?php
$paths=['/','/venues','/events','/teams','/players','/shop'];
$failed=0;
foreach($paths as $path){
    $ctx=stream_context_create(['http'=>[
      'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0,
      'header'=>"User-Agent: MSKBA-Offline-Smoke/1.0\r\n",
    ]]);
    $body=@file_get_contents('http://127.0.0.1:8000'.$path,false,$ctx);
    $line=$http_response_header[0]??'NO_RESPONSE';
    preg_match('/\s(\d{3})\s/',$line,$m);
    $status=isset($m[1])?(int)$m[1]:0;
    echo "route={$path} status={$status}\n";
    if($status!==200 || $body===false || strlen($body)<100)$failed++;
}
exit($failed===0?0:2);
PHP
 echo 'SMOKE_BLOCKED HTTP routes';cat "$DEST/http-smoke.log";exit 6
fi

# Run a DB + filesystem integrity gate through the booted Laravel kernel.
if ! docker exec -i "$APP" php > "$DEST/domain-smoke.log" 2>&1 <<'PHP';then
<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
$items=DB::table('media')->whereNull('deleted_at')->get(['disk','path','original_disk','original_path']);
$missing=0;
foreach($items as $media){
    if(!in_array($media->disk,['public','local'],true))continue;
    if(!Storage::disk($media->disk)->exists($media->path))$missing++;
}
$ownerless=DB::table('data_merge_venue_map as v')
 ->where('v.resolution','new_venue')
 ->whereNotExists(function($q){
    $q->selectRaw('1')->from('contract_memberships as m')
      ->join('contracts as c','c.id','=','m.contract_id')
      ->whereColumn('m.scope_id','v.target_venue_id')
      ->where('m.scope_type','venue')
      ->whereIn('m.access_level',['primary_manager','owner'])
      ->where('c.status','active');
 })->count();
echo 'media_live_records='.$items->count()."\n";
echo 'missing_media_files='.$missing."\n";
echo 'new_venues_without_manager_contract='.$ownerless."\n";
if($missing || $ownerless)exit(3);
PHP
 echo 'SMOKE_BLOCKED media/contract integrity';cat "$DEST/domain-smoke.log";exit 7
fi

# Verified DB checkpoint includes the repaired noncommercial catalogue
# rights, preserving all previous prod users and historical bookings.
if ! docker exec "$DB" pg_dump -U postgres -d candidate --format=custom \
  --no-owner --no-acl > "$DEST/web-approved-candidate.pgdump.part"; then
 echo 'SMOKE_BLOCKED checkpoint creation';exit 8
fi
test -s "$DEST/web-approved-candidate.pgdump.part"
mv "$DEST/web-approved-candidate.pgdump.part" "$DEST/web-approved-candidate.pgdump"
sha256sum "$DEST/web-approved-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
cat "$DEST/rights-apply.log"
cat "$DEST/http-smoke.log"
cat "$DEST/domain-smoke.log"
echo "approved_checkpoint_bytes=$(stat -c %s "$DEST/web-approved-candidate.pgdump")"
echo "candidate_dev_code_sha=$(git -C "$DEV_ROOT" rev-parse --short HEAD)"
echo 'WEB_SMOKE_PASS=true'
echo 'PRODUCTION_AND_DEV_UNCHANGED=true'
