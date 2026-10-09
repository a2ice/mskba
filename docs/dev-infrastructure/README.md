# MSKBA development environment — staged rollout

## Safety boundaries

- Production: `/var/www/mskba`, Docker Compose project `mskbanew`, branch `main`, theme `mskba_dark`. Never modify from this workflow.
- Staging: `/var/www/mskba-dev-next`, Compose project `mskba-dev`, branch `dev`, theme `mskba_app`.
- Staging has independent `dev_postgres` and `dev_redis` named volumes. No production network, credentials, uploads, API keys or Telegram webhook are reused.
- Docker dev nginx listens only on `127.0.0.1:8001`; host Nginx keeps HTTPS and will proxy after manual cutover.
- There is no automatic deploy until `ENABLE_DEV_DEPLOY=true` is set in GitHub repository variables **and** `.dev-bootstrap-complete` has been created on VDS.
- Current VDS has ~2 GB RAM and ~8.6 GB free disk. Do not start dev services until resource capacity and backups are reviewed. CI compiles frontend; deploy uses tracked `public/build` artifacts for now.

## One-time bootstrap (manual checklist; not executed by GitHub Actions)

1. Identify users of both existing PostgreSQL containers and confirm firewall provider policy (UFW was inactive; host ports 5432/6379 publicly bound). Fix exposure in a separate reviewed maintenance window without locking SSH access.
2. Create and independently verify backups of old `/var/www/mskba-dev` (including `.env`, uploads and its MySQL database if retained). Verify restoration. Existing `public/_phpinfo.php` and `public/_ver.php` should not become public on the new server.
3. Arrange a CLEAN clone of `git@github.com:a2ice/mskba.git` at `/var/www/mskba-dev-next` on branch `dev`, preserving the old installation backup outside the web root. Do not run `git clean` on old dev.
4. Copy `.env.dev.example` to `.env` on VDS and fill unique `APP_KEY`, DB password, Reverb secrets, and local-only integrations. Confirm correct `.env` values; disable Telegram consumers, outgoing notifications and paid integrations until explicitly tested. Never copy prod secrets.
5. VDS has only ~2 GiB RAM. The current bootstrap reuses `mskbanew-phpfpm:latest` through an untracked server-local `compose.dev.local.yaml` override with `build: !reset null` for `phpfpm` and `reverb`. Do NOT rebuild PHP on VDS. This is a temporary dependency on a production-tagged image, not a long-term isolation solution; replace with an independently versioned dev image from a registry.
6. Test `docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml config`, then start dev database/Redis, install dependencies, migrate only dev DB, and smoke-test `127.0.0.1:8001` using the host header.
7. Prepare host Nginx dev config to proxy `dev.mskba.ru` to `127.0.0.1:8001`, retain existing TLS; use `nginx -t` before reload. Verify HTTPS, assets, auth, uploads, WebSockets, production URL and logs. Do not touch the production vhost.
8. After verifying frontend assets, mail/Telegram/payment isolation, cookie security, file permissions, and rollback, create `.dev-bootstrap-complete` in staging project root and configure GitHub `development` environment secrets `DEV_SERVER_HOST` and `DEV_SERVER_SSH_KEY`. Set repository variable `ENABLE_DEV_DEPLOY=true` only after manual approval.

## Deployment rules

- PR -> `dev`: CI; merged push to `dev`: CI and then deploy only if CI succeeds and bootstrap gate is enabled.
- A newer push invalidates the older CI commit; the CI `deploy_dev` job (with `needs: test`) compares the verified SHA with current `origin/dev`.
- `main` production deploy is unchanged. PR `dev` -> `main` is an explicitly approved release.
- No automated DB seeding, uploads copying, destructive cleanup or Telegram polling in staging.
- Queue worker and scheduler are optional via `--profile workers` and must not be enabled until external side effects are isolated.

## Rollback

Prior to first cutover preserve old dev directory and host Nginx config; revert only the dev vhost and reload Nginx after `nginx -t`. For a bad code deployment, disable `ENABLE_DEV_DEPLOY`, restore a known-good dev commit and associated backup after confirming migration compatibility; do not assume rolling back Git reverses DB migrations. Never roll back the production database or production Compose stack.

## Current cutover and activation safeguards (2026-10-09)

- Public dev HTTPS is routed to localhost:8001 and renders `mskba_app`; production remains `mskba_dark`.
- Server `.env` must remain outside Git with `deploy:www-data`, mode `0640`. `storage` and `bootstrap/cache` must be writable by `www-data` without changing production ownership.
- `compose.dev.local.yaml` must remain server-only (never commit secrets or use a global compose override).
- Before enabling automation, validate image compatibility with the current `composer.lock`, check outbound messaging/payment integrations are disabled, and confirm WSS route strategy.
- Development environment must contain `DEV_SERVER_HOST` and `DEV_SERVER_SSH_KEY`, and repository variable `ENABLE_DEV_DEPLOY` must stay unset until an authorized manual deployment/rollback trial.
- The workflow currently deploys tracked `public/build` files; CI's successful Vite build is not uploaded as a deployment artifact. Verify that committed assets correspond to the release; a future revision should deploy immutable CI-built artifacts/images.
- The first enabled run must be supervised; successful HTTP `/` alone is not sufficient proof of auth, WebSocket, uploads, or side-effect isolation.
