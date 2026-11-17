# Production Laravel Scheduler → host cron — 2026-10-11

## Purpose

Reduce idle RAM used by the long-lived `php artisan schedule:work` container. **Laravel Scheduler and all seven PHP scheduling rules are retained**; only the once-per-minute trigger changes to host cron calling `php artisan schedule:run` inside the existing production `mskbanew-phpfpm` container. This can reduce idle RAM, not necessarily the brief minute-by-minute peak.

Production scheduled jobs in `routes/console.php`:
- Every minute: `coordination:close-expired`, `venue-booking:close-expired-attendance`, `venue-booking:dispatch-outbox`, `venue-booking:expire-due`.
- Every five minutes: `venue-booking:reconcile-payments`.
- Daily (according to application timezone): `identity:scan-user-duplicates` 03:15 and `privacy:prune-tracking` 03:45.
Existing Laravel `onOneServer` and `withoutOverlapping` locks remain intact.

## Legacy deployment is still active — do not remove

The deploy user's old cron already contains `* * * * * cd /var/www/mskba-dev && php artisan schedule:run >> /dev/null 2>&1`. This points to a **different**, older staging application; its `.env` uses **host MySQL** at `127.0.0.1:3306` and four once-per-minute scheduled jobs. A host-level `www-data` Laravel queue worker and live MySQL connection were observed. Do NOT stop old cron, MySQL or old worker without checking their owner and dependencies.

Separately, older `mskbabrandnew-db` and `mskbabrandnew-redis` containers share an isolated network but publish ports to all interfaces. No active DB clients were seen during spot-check; this does not establish they are safe to remove. Verify all host clients and take restorable data backups before decommission. No volumes may be deleted during this scheduler task.

## New production cron runner

Tracked script: `ops/scheduler/production-cron-tick.sh`. Install a copy outside the Git checkout at `/home/deploy/bin/mskba-production-cron-tick.sh` with mode 0755.

Its safety properties:
1. Exclusive `flock` prevents overlapping cron invocations.
2. If old `mskbanew-scheduler` is **running**, the cron invocation skips, preventing duplicate execution during a legacy full production deploy.
3. If production PHP-FPM is unavailable, fail closed and write to syslog.
4. Call `docker exec mskbanew-phpfpm php artisan schedule:run --no-interaction`. On success, touch the heartbeat `/home/deploy/.mskba-production-scheduler-last-success`.

### Supervised installation and switch

Back up the existing deploy-user crontab and original scheduler container inspection:
```bash
crontab -l > /home/deploy/mskba-crontab-before-prod-scheduler-20261011.bak
docker inspect mskbanew-scheduler > /home/deploy/mskba-scheduler-before-cron-20261011.json
```
Install runner and append **once**, retaining the old staging cron:
```cron
* * * * * /home/deploy/bin/mskba-production-cron-tick.sh
```
First run the runner manually **while old scheduler is running**; it must skip, and not create the heartbeat. Confirm `systemctl is-active cron` and `crontab -l`. Then switch:
```bash
docker update --restart=no mskbanew-scheduler
docker stop -t 25 mskbanew-scheduler
```
Within two or three minute boundaries, verify the heartbeat timestamp advances, `schedule:list` still lists seven rules, HTTP 200 for prod, no syslog errors, and the old scheduler is stopped. Monitor CPU/RAM during minute boundaries and normal idle.

The tracked `.github/workflows/deploy.yml` is updated in this branch to check host cron prerequisites and omit the scheduler service from builds/starts/restarts on **future** `main` releases. Until that code actually reaches `main`, the current main deploy workflow may restart the old scheduler: host cron then skips automatically; re-stop old scheduler after the deploy. This is intentionally **not** a reason to trigger a full production deploy today.

### Rollback

If health or scheduled execution fails:
```bash
docker update --restart=unless-stopped mskbanew-scheduler
docker start mskbanew-scheduler
```
The new cron runner sees the old scheduler and skips. Confirm old scheduler logs resume, then remove **only** the newly added production cron line (restore backed-up crontab if there are no other intervening edits). Leave the old staging cron intact and never delete data.

## Main release: mandatory Gotenberg safeguard

Track [GitHub Issue #308](https://github.com/a2ice/mskba/issues/308) until the next main deployment includes the approved `compose.prod.yaml` flags `--chromium-auto-start=false`, `--chromium-idle-shutdown-timeout=15s` and verifies server-only `GOTENBERG_TIMEOUT_SECONDS=45`, actual PDF smoke and rollback procedure. The CI production Compose check now fails if flags are accidentally lost. It will not silently change production.

## Further checks

Monitor daily schedules including 03:15 and 03:45 in the application's timezone, and ensure no duplicates or missed deadlines. Later, audit legacy staging/MySQL and orphan DB/Redis separately, including backups and access controls.
