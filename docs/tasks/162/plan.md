# Task 162 — plan

- [x] Ввести `VenueCourt` и `venue_courts`.
- [x] Backfill существующих Venue одним primary court.
- [x] Автоматически создавать primary court для новых Venue.
- [x] Добавить account CRUD залов с текущими venue permissions.
- [x] Добавить `supports_halves`, primary selection и защиту последнего зала.
- [x] Добавить regression tests.
- [x] PR CI green — GitHub Actions run `34448280358`.
- [x] Merge в `main` — PR #156, merge `a7650e3f321d35630bc17146a815c95775526e5e`.
- [x] Production deploy green — GitHub Actions run `34448434938`.

Следующие независимые задачи:

- Task 163 — привязать booking/quote/conflict/event к конкретному `VenueCourt`.
- Task 164 — публичный court selector, `/courts/{court}` URL и court-scoped occupancy/activity.
