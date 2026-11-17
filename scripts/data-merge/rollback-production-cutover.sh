#!/usr/bin/env bash
# Task 325 emergency rollback: old code, original .env/DB, original web.
# Never drops either database or deletes any user data.
set -euo pipefail
umask 077
ROOT=/var/www/mskba
DIR=/home/deploy/mskba-cutover-backups/release-20261117
OLD_SHA=cc33a60b7791d1038ca1db0417c1be14aae7a651
cd "$ROOT"
test -f "$DIR/original.env"
test -s "$DIR/original-production.pgdump"
test -s "$DIR/original-storage.tar.gz"
test -s "$DIR/original-build.tar.gz"
( cd "$DIR" && sha256sum -c --status SHA256SUMS )
test "$(cat "$DIR/original-git-sha")" = "$OLD_SHA"
test "$(git cat-file -t "$OLD_SHA")" = commit
COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml"
# Keep the site in maintenance until old code is safe to serve.
$COMPOSE exec -T phpfpm php artisan down --retry=120 >/dev/null 2>&1 || true
cp -p "$DIR/original.env" .env
git reset --hard "$OLD_SHA"
git clean -fd
$COMPOSE build phpfpm queue telegram-inbound-queue telegram-background-queue reverb
$COMPOSE up -d db redis
$COMPOSE run -T --rm phpfpm composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader </dev/null
if ! $COMPOSE --profile build run -T --rm node </dev/null;then
  tar -xzf "$DIR/original-build.tar.gz" -C "$ROOT"
fi
test -f public/build/manifest.json
$COMPOSE run -T --rm phpfpm php artisan optimize:clear </dev/null
$COMPOSE run -T --rm phpfpm php artisan config:cache </dev/null
$COMPOSE up -d --force-recreate phpfpm nginx queue telegram-inbound-queue telegram-background-queue reverb
$COMPOSE exec -T phpfpm php artisan up
if grep -Fxq telegram "$DIR/running-services-before";then
  $COMPOSE --profile polling up -d telegram
fi
test "$(grep '^DB_DATABASE=' .env | cut -d= -f2-)" = "$(cat "$DIR/original-db-name")"
touch "$DIR/ROLLED_BACK"
echo 'PRODUCTION_ROLLBACK_COMPLETED=true'
echo 'ORIGINAL_DB_AND_MEDIA_PRESERVED=true'
echo "restored_git_sha=$(git rev-parse HEAD)"
