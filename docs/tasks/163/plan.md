# Task 163 — plan

- [x] Добавить nullable `venue_court_id` в quotes/bookings/events без ложного backfill исторических строк.
- [x] Добавить court relations в модели.
- [x] Перенести conflict resolution на `venue + court + scope + time`.
- [x] Сохранить консервативную семантику legacy `venue_court_id = NULL`.
- [x] Использовать `VenueCourt.supports_halves` как физическое ограничение.
- [x] Передавать court через rental quote → booking → generated Event.
- [x] Привязать обычное создание/редактирование Event к court.
- [x] Добавить court selector в rental quote UI и court-scoped availability projection.
- [x] Добавить regression tests изоляции conflicts.
- [x] PR CI green — GitHub Actions run `34451611809`.
- [x] Merge в `main` — PR #158, merge `211d870535d45d18144ebc3547dbf7db8b847e04`.
- [x] Production deploy green — GitHub Actions run `34451817468`.

Следующая задача: Task 164 — публичный court selector, nested URL и court-scoped occupancy/activity.
