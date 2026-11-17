# Production Gotenberg: lazy Chromium — 2026-10-11

## Approved scope

Only optimize the existing Gotenberg PDF renderer. The HTTP container stays running; its Chromium subprocess starts on a PDF request and automatically stops after 15 seconds idle. This is **not** a fully on-demand Docker container and does **not** save its entire resident footprint. Never mount the Docker socket into the Laravel application.

The change to `compose.prod.yaml` is the intended **tracked future source of truth**. It affects production **only after a controlled main release**. Merging/pushing to `main` triggers the existing **full production deployment** (`.github/workflows/deploy.yml`) — not a lightweight Gotenberg-only change. Coordinate that release separately and check disk/RAM first. Do not bypass Git history or trigger an unexpected full deployment.

## Immediate runtime rollout (11 October 2026)

An explicitly approved narrow change was applied on VDS by recreating **only** `mskbanew-gotenberg`, without changing Git checkout, databases, production PHP containers or the full deployment workflow:

- The server-only, permissions-restricted override is `/home/deploy/mskba-gotenberg-lazy-20261011.yaml`.
- The original container inspection is backed up at `/home/deploy/mskba-gotenberg-before-20261011.json`.
- From `/var/www/mskba`, the targeted command was:

```bash
docker compose -f compose.yaml -f compose.prod.yaml   -f /home/deploy/mskba-gotenberg-lazy-20261011.yaml   up -d --no-deps --no-build gotenberg
```

The override sets:

```yaml
services:
  gotenberg:
    command:
      - gotenberg
      - --chromium-auto-start=false
      - --chromium-idle-shutdown-timeout=15s
```

The current Laravel PDF client timeout of 30 seconds was dangerously close to the observed **28.45-second** first cold production PDF. We therefore set `GOTENBERG_TIMEOUT_SECONDS=45` in **server-only production `.env`**, then regenerated Laravel's config cache, with no secrets committed. This changes the Laravel→Gotenberg HTTP timeout, *not* the upstream browser/Nginx request timeout. The original `.env` and `bootstrap/cache/config.php` backups are stored under `/home/deploy/mskba-env-before-gotenberg-20261011.bak` and `/home/deploy/mskba-configcache-before-gotenberg-20261011.bak` (restricted).

**Observations**: Before rollout, `acquisition:documents:smoke` successfully generated a ~1.74 MB flyer PDF in **16.96s**, and Gotenberg consumed ~319 MiB immediately after conversion. After rollout, Gotenberg was **6.47 MiB before first request**, first real cold flyer took **28.45s** (successful), ~318 MiB immediately after PDF; after some minutes idle with zero Chromium processes it decreased to **115.6 MiB**. A follow-up smoke after raising client timeout to 45s succeeded in **18.44s**. These measurements are snapshots; do not treat them as guaranteed steady-state RAM or latency. Both production and dev returned HTTP 200 throughout checks.

**Operational caveat:** The immediate host-only override was intentionally not inserted into the existing full production deploy workflow. Any subsequent standard `main` release, which uses only `compose.yaml` and `compose.prod.yaml`, may recreate Gotenberg from the tracked config and **lose the lazy settings until this tracked Compose patch reaches `main`**. When promoting dev to main, explicitly verify that `compose.prod.yaml` includes the two flags above and the `.env` timeout remains 45. Do not rely on the server-only override for permanent deployment.

## Verification

```bash
docker inspect mskbanew-gotenberg --format '{{json .Config.Cmd}}'
docker stats --no-stream mskbanew-gotenberg
docker exec mskbanew-gotenberg sh -c 'ps -eo comm | grep -c chromium || true'
docker exec mskbanew-phpfpm php artisan config:show document-templates.gotenberg
docker exec mskbanew-phpfpm php artisan acquisition:documents:smoke
curl -fsS -o /dev/null -w 'HTTP %{http_code}\n' https://mskba.ru
```

Perform the smoke only deliberately: it can allocate over 300 MiB RAM and raise CPU load for 15–30 seconds. Compare cold and warm timings and **idle memory a few minutes later**, not immediately after rendering. Monitor for timeout errors, particularly under heavier production load.

## Immediate rollback without full deploy

If PDF errors or performance regressions appear, restore Gotenberg's previous default command by recreating **only** it using the existing production Compose files **without** the temporary override:

```bash
cd /var/www/mskba
docker compose -f compose.yaml -f compose.prod.yaml up -d --no-deps --no-build gotenberg
docker inspect mskbanew-gotenberg --format '{{json .Config.Cmd}}'
docker exec mskbanew-phpfpm php artisan acquisition:documents:smoke
```

**This rollback applies only while `compose.prod.yaml` on the VDS is the older version without the lazy flags.** Once the tracked flags reach `main`, use a reviewed rollback commit or a one-off override that explicitly sets `command: ["gotenberg"]`. Do not blindly run the above and assume it reverted the newer Git configuration. The client timeout of 45s is compatible with the old renderer; it can stay in place. Backups and the original command are retained.

Avoid `docker system prune --volumes`, changing databases, or restarting application/webhook/queue services as part of this narrow rollback.

## Next checks

- Monitor true idle RAM and one or two production PDF executions before declaring sustained savings.
- Track the template missing from the staging `mskba_app` theme separately; it does not affect the production `mskba_dark` smoke command.
- Keep MySQL, old Postgres/Redis containers and Telegram production workers untouched pending their independent dependency and backup reviews.
