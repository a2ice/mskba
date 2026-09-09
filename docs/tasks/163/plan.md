# Task 163 — plan

- [x] Добавить `venue_court_id` в quotes/bookings/events и backfill primary court.
- [x] Добавить court relations в модели.
- [x] Перенести conflict resolution на `venue + court + scope + time`.
- [x] Сохранить консервативную семантику legacy `venue_court_id = NULL`.
- [x] Использовать `VenueCourt.supports_halves` как физическое ограничение.
- [x] Передавать court через rental quote → booking → generated Event.
- [x] Привязать обычное создание/редактирование Event к court.
- [x] Добавить court selector в rental quote UI и court-scoped availability projection.
- [x] Добавить regression tests изоляции conflicts.
- [ ] PR CI green.
- [ ] Merge в `main` после Task 162.
- [ ] Production deploy green.

Следующая задача: Task 164 — публичный court selector, nested URL и court-scoped occupancy/activity.
