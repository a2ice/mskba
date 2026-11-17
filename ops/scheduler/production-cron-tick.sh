#!/usr/bin/env bash
# Production Laravel schedule:run via host cron, with fail-closed legacy guard.
set -Eeuo pipefail
export PATH=/usr/bin:/bin
LOCK=/home/deploy/.mskba-production-scheduler.lock
HEARTBEAT=/home/deploy/.mskba-production-scheduler-last-success
exec 9>"$LOCK"
/usr/bin/flock -n 9 || exit 0

# During a legacy/full deployment the old schedule:work container might come
# back. Do not execute schedule:run concurrently with schedule:work.
if /usr/bin/docker ps --filter name='^/mskbanew-scheduler$' --filter status=running --format '{{.Names}}' | /usr/bin/grep -Fxq 'mskbanew-scheduler'; then
  exit 0
fi

if [ "$(/usr/bin/docker inspect --format '{{.State.Running}}' mskbanew-phpfpm 2>/dev/null)" != "true" ]; then
  /usr/bin/logger -t mskba-prod-schedule 'ERROR: PHP-FPM container is not running; skipped schedule:run'
  exit 1
fi

if ! /usr/bin/docker exec mskbanew-phpfpm php artisan schedule:run --no-interaction >/dev/null 2>&1; then
  /usr/bin/logger -t mskba-prod-schedule 'ERROR: Laravel schedule:run failed'
  exit 1
fi
/usr/bin/touch "$HEARTBEAT"
