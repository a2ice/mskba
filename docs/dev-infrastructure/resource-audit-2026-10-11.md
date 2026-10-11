# MSKBA VDS resource audit and on-demand PDF pilot — 2026-10-11

## Observations (read-only until build-cache prune)

- VDS has about 1.9 GiB physical RAM, ~560 MiB available, ~680 MiB swap in use. These are point-in-time measurements, not capacity guarantees.
- Docker container memory is **not** the whole machine. Host `dockerd` had ~316 MiB RSS, host MySQL ~114 MiB RSS, system journald ~53 MiB RSS, and multiple PHP-FPM workers. RSS totals cannot safely be added to Docker Stats due to shared memory and caches.
- Gotenberg production container (`mskbanew-gotenberg`) runs continuously; around 86–96 MiB was attributed by Docker at measurement time, with Chromium processes sometimes running after conversion. Historical peaks may be higher.
- Gotenberg is **in use**: admin acquisition flyer PDF endpoint in `AdminAcquisitionController::flyerPdf`, and diagnostic `acquisition:documents:smoke`. An observed production conversion succeeded in about 12.7 s. Disabling PDF conversion without a replacement breaks that feature.
- Three Postgres/Redis stacks were observed: `mskbanew-*` (production), `mskba-dev-*` (staging) and older `mskbabrandnew-db/redis`. The old pair shares its own network without application containers and displayed no apparent application DB connections at that instant; **not proof of disuse**. Its published ports bound to all interfaces. Validate host consumers, historic data and backups before stopping, deleting or closing access.
- Host MySQL service is active; a TCP connection to 127.0.0.1:3306 was observed during the audit. Ownership/use remains unverified. **Do not stop MySQL** without tracing that client, websites and backups.
- Production Telegram inbound/background queue workers use roughly 35 MiB each in Docker Stats. Keep them running until the consequences for real bot users, webhook and backlog are assessed.
- Laravel Scheduler currently uses `php artisan schedule:work` in a long-running production container (~56 MiB). `routes/console.php` defines seven scheduled commands: four every minute, one every five minutes, two daily. Host `cron` is already active. Switching to an every-minute cron call of `php artisan schedule:run` **may** save idle RSS but requires atomic switchover, locking, one host/one environment routing, and tests for task execution and logging; never run both.
- Disk was ~28/38 GB used (~9.9 GB free) before pruning. `docker builder prune -f --filter until=168h` recovered 0 bytes, while `docker builder prune -f --reserved-space 2GB` recovered about 5 GB of *unused build cache*, increasing free space to ~15–16 GB. No images, containers, or volumes were intentionally deleted. Cache loss can make future image builds slower; no application data was pruned.

## Gotenberg pilot: staged, not production

The Gotenberg v8 image installed on the VDS supports `--chromium-auto-start=false` and `--chromium-idle-shutdown-timeout=15s`. The Chromium browser starts on first conversion and is configured to exit after 15 seconds of inactivity on the staging pilot; the **Gotenberg HTTP container itself does not auto-stop**. A full container-level wake/sleep mechanism would require a separate, privileged supervisor/proxy design; **never mount the Docker socket into Laravel**.

Staging's `compose.dev.yaml` now contains an optional `gotenberg` service behind the `pdf` profile, with `restart: "no"`, so ordinary staging deploy does not keep it running. This is an **operator-triggered** start/stop pilot, not transparent, fully automatic request-triggered container activation.

From `/var/www/mskba-dev-next` on VDS:

```bash
# First review env, compose override and available capacity; only in staging.
docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml --profile pdf config --quiet
docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml --profile pdf up -d --no-build gotenberg
docker stats --no-stream mskba-dev-gotenberg-1
# Staging smoke test; generates a synthetic PDF and does not persist a campaign:
docker exec mskba-dev-phpfpm-1 php artisan acquisition:documents:smoke
# Measure RAM during conversion and after >40 s idle, then stop staging PDF service:
docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml --profile pdf stop gotenberg
```

During the 2026-10-11 staging experiment, the Laravel-specific `acquisition:documents:smoke` command failed *before Gotenberg* because Blade view `documents.acquisition-flyer-a4` exists only in the `mskba_dark` theme, not the staging `mskba_app` theme. A separate direct multipart HTML-to-PDF request from the staging PHP-FPM container succeeded: **cold 7.53 s**, **warm 1.07 s**, both HTTP 200 with valid PDF headers (15,062 bytes). Gotenberg was ~6 MiB before conversion and ~320 MiB immediately afterward, with a 512 MiB memory limit for the temporary pilot container. These figures describe a synthetic HTML document, not the actual flyer. In a first run configured with a **2m timeout**, Chromium was still present (~215 MiB, several processes) at the 135-second follow-up; the reason is unresolved. A second, separately controlled test with **15s idle timeout** succeeded: cold **14.26 s**, warm **4.18 s** (HTTP 200, PDFs valid), ~13 MiB before conversion, and **102.7 MiB at 40 seconds idle, with zero Chromium processes**. The pilot was then explicitly stopped and removed. This verifies Chromium-only idle shutdown under the 15s setting; it does not demonstrate full Docker-container-on-demand behaviour or guaranteed 200–300 MiB permanent host-level savings. Restore cross-theme access to the document template in a separate, reviewed application change.

Only run the application smoke test when its document template issue is resolved and capacity is sufficient; abort if production site degrades. The renderer's HTTP timeout is currently 30 seconds; a cold Chromium start plus PDF conversion may exceed that on this 2 GiB VDS. Do not silently increase timeout globally until measured.

### Evaluation and next actions

1. Measure idle/stressed/stopped Gotenberg RAM and cold/warm PDF latency on staging, confirm successful PDF and no production impact.
2. If the browser idle shutdown saves enough memory, propose production adoption with explicit release/rollback approval; **no production compose change in this pilot**.
3. If keeping Gotenberg running remains too expensive, design and test an authenticated **host-side** container-on-demand proxy or isolated worker; measure cold start and failure handling without granting application access to Docker.
4. Audit old Postgres/Redis and host MySQL dependencies and take restorable backups before proposing decommission.
5. Explore cron-based scheduling separately; do not alter running production scheduling or Telegram queue workers during this pilot.

## Rollback

The pilot adds one optional staging service. Stop it (command above) and revert this `compose.dev.yaml` addition if it causes regressions. Production containers and database volumes are untouched. CI/deploy should continue to work for staging without the `pdf` profile.
