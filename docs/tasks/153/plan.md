# Task 153 — План

1. [x] Синхронизировать фактический 40-символьный порог заголовка с tooltip UX.
2. [x] Сохранить полный tooltip только для действительно обрезанного заголовка.
3. [x] Поддержать переходные booking-first данные: если `venue_bookings.event_id` пуст, искать Event по `events.booking_id`.
4. [x] Не раскрывать через занятые слоты private/draft мероприятия.
5. [x] Добавить regression-тесты для обратной связи booking→event и приватности.
6. [ ] PR CI (`php artisan test` + frontend build).
7. [ ] При зелёном CI merge в `main`, затем production-проверка.
