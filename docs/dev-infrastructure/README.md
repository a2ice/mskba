# MSKBA development environment — staged rollout

For interactive, password-once SSH access via a connected Mac and Remote Desktop Commander, see [Remote Commander SSH procedure](remote-commander-ssh.md).

## Safety boundaries

- Production: `/var/www/mskba`, Docker Compose project `mskbanew`, branch `main`, theme `mskba_dark`. Never modify from this workflow.
- Staging: `/var/www/mskba-dev-next`, Compose project `mskba-dev`, branch `dev`, theme `mskba_app`.
- Staging has independent `dev_postgres` and `dev_redis` named volumes. No production network, credentials, uploads, API keys or Telegram webhook are reused.
- Docker dev nginx listens only on `127.0.0.1:8001`; host Nginx keeps HTTPS and will proxy after manual cutover.
- Automatic dev deploy requires repository variable `ENABLE_DEV_DEPLOY=true`, successful same-run CI tests, and the existing `.dev-bootstrap-complete` marker on VDS.
- VDS has ~2 GB RAM; dev containers are already running. Monitor capacity before introducing new services. CI compiles frontend; deploy currently uses tracked `public/build` artifacts.

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
- Before changes to image/dependencies or outbound integrations, validate image compatibility, external side effects and WSS route strategy.
- GitHub `development` environment uses `DEV_SERVER_HOST` and `DEV_SERVER_SSH_KEY`; automatic deploy is gated by repository variable `ENABLE_DEV_DEPLOY`. Disable this variable to halt future automatic deploys.
- The workflow currently deploys tracked `public/build` files; CI's successful Vite build is not uploaded as a deployment artifact. Verify that committed assets correspond to the release; a future revision should deploy immutable CI-built artifacts/images.
- The first enabled run must be supervised; successful HTTP `/` alone is not sufficient proof of auth, WebSocket, uploads, or side-effect isolation.

## Outbound integration deployment gate

- Staging remains `APP_ENV=staging`, `APP_DEBUG=false`, and `APP_THEME=mskba_app`. Live provider credentials may be configured deliberately for functional testing. The deploy accepts `MAIL_MAILER=log|smtp` and requires `TELEGRAM_UPDATES_TRANSPORT=disabled`; it does not configure webhooks or launch workers.
- No staging workers or scheduler are started by deployment. Payment and other providers still require an application-level audit before creating the bootstrap marker. Shared live integrations are permitted by explicit project decision; test sends must be intentional, with known recipients.
- This is configuration validation, not a network egress firewall. Verify actual server `.env` and side-effect code paths independently.

## Shared-provider integration warning

- Using the **same Telegram bot token** in staging and production is not equivalent to running two independent bots. Telegram stores a single webhook URL per bot; setting the dev webhook replaces the prod webhook. Polling against a webhook or competing pollers also interferes with delivery. Keep incoming updates disabled on dev until one deliberate routing strategy is chosen. Outbound test messages through a shared bot can reach real chats.
- VK ID callback URLs must be permitted by the existing VK application; dev callback / credentials need verification. SMTP credentials can send real mail, so test only with intended recipients.
- No automated outgoing notifications are tested or triggered by CI; changing these policies only permits configured credentials and does not prove provider connectivity.

## Decision: one shared Telegram bot (temporary; 2026-10-10)

**Accepted temporary architecture:** production (`mskba.ru`) remains the **sole receiver** of Telegram bot updates/webhook. Staging (`dev.mskba.ru`) may use the **same bot token only for manually controlled outgoing Telegram API calls** to explicitly selected test chats/recipients. This does **not** provide full dev validation of inbound callbacks, commands, Telegram login or Mini App flows; those require additional work. Outbound messages are sent by the same bot identity and are visible to recipients as ordinary bot messages.

**Do not:** change the bot's webhook to dev, call `telegram:configure-updates` from dev (the polling mode deletes the current webhook), start dev polling/webhook consumers, or enable background queues/scheduler that can emit uncontrolled bot messages. Keep `TELEGRAM_UPDATES_TRANSPORT=disabled` on dev for the shared-bot phase. Note that `telegram:configure-updates` does not currently implement `disabled` as a safe no-op; never invoke it on dev. Set the dev token only when explicitly testing outgoing messages. Keep secrets only in the server `.env`, not in Git.

**Technical debt — separate inbound integration properly:** design and implement an isolated Telegram bot for dev (preferred) or a deliberate production-to-dev event router with authenticated environment routing, distinct callback handling, idempotency and audit logs. Test Mini App authentication, callbacks, webhooks, and outgoing notifications independently before turning on dev consumers. The CI now enforces `TELEGRAM_UPDATES_TRANSPORT=disabled`; revisit that guard only as part of an explicitly approved Telegram integration redesign.

## Verified first supervised deploy (2026-10-09)

- Manual GitHub Actions run [#37999883228](https://github.com/a2ice/mskba/actions/runs/37999883228) deployed `25254d19` to staging; PHP tests and frontend build passed; `deploy_dev` succeeded.
- Dev HTTP returned 200, production HTTP returned 200; PostgreSQL reported `Nothing to migrate`. Readiness retry succeeded on attempt 2 after container recreation.
- Dev DB pre-deploy backup: `/home/deploy/mskba-dev-before-deploy-20261009-220141.dump`, custom format validated with `pg_restore -l`.
- Limitations: shared PHP image is temporary, Composer dependency upgrades are intentionally rejected during in-place deploy, and the build delivered to staging is the committed `public/build` directory rather than the CI output. WebSocket, authenticated and outbound integration flows need end-to-end checks.
- Next milestone: enable `ENABLE_DEV_DEPLOY=true`, push a documentation-only commit from the local checkout, and confirm the `push`-triggered test/deploy pipeline reaches Success while both URLs remain HTTP 200.

## Staging superadmin provisioning (2026-10-10)

- The staging-only `deploy_dev` job runs `Database\Seeders\SuperadminSeeder` **after dev migrations**. It never runs the entire `DatabaseSeeder` automatically. Production's separate deployment does not invoke this seeder.
- On staging, set `SUPERADMIN_SEED_PASSWORD` to a **unique random 64-character hex secret** in `/var/www/mskba-dev-next/.env` (owner `deploy:www-data`, mode `0640`). The CI deploy gate rejects staging deploys if it is absent or does not match the expected format. Do not store the secret in Git, GitHub workflow output, or chat.
- The password is resolved through `config/seeding.php` so Laravel configuration caching is supported. This variable is per-instance; if future environments need provisioning, use separate secrets. **Production provisioning is disabled within the seeder** even if a secret is present. There is no production superadmin seed in the deployment workflow.
- The seeder is **create-only**: it provisions the `superadmin` login with `SUPERADMIN` role and `CONFIRMED` status only when no matching account exists. Repeated runs never reset a password or overwrite the user's profile/status. Username collision with a non-admin or soft-deleted account fails for manual review rather than escalating privileges. Missing/weak password fails for a new account.
- Recovery/rotation: change the existing user's password through an approved, authenticated administrator flow or a separately reviewed maintenance procedure. **Changing the `.env` seed value after creation does not rotate the password**. Avoid running production seed tasks to administer privileged accounts.
- The operator must provision the staging-only bootstrap secret in the server's protected `.env` using their own authenticated terminal and store the value securely in a password manager. Do not print or transmit secrets in CI logs, repository content, or chat.
- The previously hard-coded bootstrap password was removed from repository source; consider any account using that historical password compromised and rotate it via a separately approved production change.

## Dev FAQ support mail (2026-10-10)

FAQ questions are delivered directly using Laravel `SupportQuestionMail`, without saving them in the application database. The historical `support_questions` table is removed by a safety-checked follow-up migration only if empty. The staging server currently uses `MAIL_MAILER=log` and has no SMTP account configured, so the form responds HTTP 503 instead of claiming mail was sent. To activate real delivery on Dev, provision independently approved SMTP credentials in the VDS `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and optional `MAIL_SCHEME`), then clear config cache and verify delivery using an approved recipient. Do not reuse production credentials without explicit approval. Recipient configured as `SUPPORT_EMAIL`, default `support@mskba.ru`. No production changes.

## Staging maintenance fallback (2026-10-10)

- Source: `public/maintenance.html` is self-contained HTML (inline styles and SVG) with support, VK and Telegram links copied from the public defaults in `config/support.php` and `config/services.php`. Update this static snapshot whenever contact configuration changes.
- Dev Nginx integration: `ops/nginx/mskba-dev-maintenance.inc` is a server-level snippet for the **Dev HTTPS vhost only**, not the production host. It returns the static document with HTTP 503 for upstream 502/503/504 and includes cache and indexing safeguards. A code deploy alone does not install it in the host Nginx configuration.
- Activation: back up the current Dev vhost; verify the active TLS server and readable static file, add this snippet at Dev `server` scope, validate the resulting Nginx configuration before reload, and check normal Dev and Prod URLs. To simulate an upstream failure, use a temporary isolated test listener rather than stopping any live containers. Restore the saved Dev vhost to roll back.
- This covers upstream failures while host Nginx is running, not outages of DNS/TLS/the entire VDS. The fallback does not resolve the root cause of any 502.

### Activation helper

Use `ops/nginx/install-mskba-dev-maintenance.sh` on VDS after review and explicit authorization. It checks the target vhost, makes a backup and applies the Dev-only snippet with configuration validation. In case of a validation failure, it restores the previous configuration. It is idempotent. Do not execute it automatically during CI/deploy.
