# FindSport: production availability after dev → prod migration

Дата включения: 18.11.2026. Область: штатные инструменты интеграции площадок в Filament.

## What is enabled

The three existing FindSport gates now default to `true` when `APP_ENV=production`
(as well as `staging`); explicit server `.env` values override defaults.
The generic external execution engine `venue_integrations.live_source_execution_enabled`
remains `false`. No external booking, payment, or arbitrary-url requests are enabled.

- `VENUE_INTEGRATIONS_FINDSPORT_PREVIEW_ENABLED`: Filament action «Получить данные»;
  two HTTPS read-only requests, fixed FindSport host, no redirects, DNS pinning,
  source validation, caching, throttle, permission and audit.
- `VENUE_INTEGRATIONS_APPLY_FINDSPORT_NATIVE_ENABLED`: manual snapshot
  «Применить к расписанию MSKBA» (existing confirm + existing validations).
- `VENUE_INTEGRATIONS_PUBLIC_SCHEDULE_REFRESH_ENABLED`: on-demand refresh of
  *already manually applied* sources in the public day calendar and quote.
  Shared lock, 300-second interval, short retry backoff on errors, stale/unknown
  states remain non-bookable.

On **production**, native import and public refresh additionally require
`permission_status=mskba_allowed`, a recorded review, an access basis, exact
FindSport URL/ID, API adapter, test-mode integration, capabilities and exactly
one mapped court. `testing_only` may be manually previewed by an authorized
administrator but never applied to live schedules or refreshed by visitors.
The global execution switch is not involved.

## Release / verification

1. After green CI, merge the production release to `main`. GitHub workflow
   `.github/workflows/deploy.yml` automatically deploys on code changes,
   runs `php artisan optimize:clear`, `config:cache`, and restarts PHP-FPM.
2. In Filament on `mskba.ru`, open the intended venue → Интеграции →
   Источники и залы and confirm the source's `mskba_allowed` access review,
   test mode, API adapter, one hall mapping, capabilities and legal basis.
3. Click «Получить данные». Check dates, slots, prices and audit. Then
   «Сохранить снимок». Do not apply a snapshot to a live court until its
   imported hours and availability have been compared with the source and
   existing MSKBA bookings.
4. If accepted, click «Применить к расписанию MSKBA» and check that only the
   mapped court's schedule changes. Open its public day dialog: before the
   300-second boundary it reads the cached applied snapshot. A later visit may
   refresh the approved source. An error never converts unknown into free.
5. Inspect source request frequency, Laravel exceptions, and imported prices.

## Emergency stop / rollback

Set any of the three `.env` flags to `false` on the server and refresh
Laravel's config cache to disable that specific layer (or all three). These
server-only values remain authoritative across subsequent code deployments.
Do not change `APP_ENV` and never commit credentials or server `.env`.
To revert code, release an inverse Git commit using the ordinary CI/deploy flow.

The migrated production venue/source records are not automatically modified by
this release. Importing a saved snapshot is always a separate explicit action.
