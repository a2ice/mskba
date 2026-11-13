# Task 320 — Notification settings and wallet transfer alerts

## Scope

In the active `mskba_app` theme, consolidate the two existing account preferences (homepage and visual theme) into a compact «Базовые настройки» card with two responsive columns. Introduce the «Уведомления» card for email and Telegram channel preferences, plus a read-only in-app status. Keep wallet transfer requests as an independent card.

## UI design system trace

- Use the existing `panel` surface, `button primary`, `muted`, `select-control` and `#chevron-down` SVG control pattern. Never show the browser-native select arrow.
- Use design tokens (`--line`, `--surface-raised`, `--accent`, `--radius-control`) rather than hardcoded light/dark palette values; use the same structure in both appearance modes.
- Display two settings per row on desktop and one per row for a narrow viewport; avoid fixed desktop widths.
- Reuse the existing user notification preference enum and server-side validated forms; no duplicate UI settings model.
- Show confirmed email and Telegram private-chat availability; never claim unconfirmed channels will receive a message.
- Realtime toast is backed by the existing notification sync endpoint, with no new popup component.

## Checks

CI: PHP application and feature tests, JavaScript tests, Vite build. Dev acceptance: verified recipient receives finance toast; confirmed email receives notification; changing each channel policy affects only that channel; appearance switch and homepage setting continue to work.
