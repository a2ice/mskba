# Task 163 — Бронирования и мероприятия в контексте конкретного зала

## Контекст

Task 162 вводит `VenueCourt` как самостоятельный физический зал внутри одного `Venue`. До этого все конфликты бронирований вычислялись только по `venue_id + time + WHOLE/HALF`, поэтому бронь одного зала фактически блокировала бы остальные залы того же объекта.

## Цель

Сделать конкретный `VenueCourt` частью booking/event identity, сохранив обратную совместимость со старыми строками и существующей логикой `WHOLE / HALF_A / HALF_B`.

## Data model

Добавить nullable `venue_court_id` с FK на `venue_courts` в:

- `venue_booking_quotes`;
- `venue_bookings`;
- `events`.

Существующие записи backfill-ятся primary court соответствующего Venue. Для событий, созданных из booking-first flow, court дополнительно синхронизируется из связанной брони.

Колонки остаются nullable на уровне БД как migration bridge: старая/неоднозначная запись без court трактуется консервативно как venue-wide и конфликтует с каждым court. Новые штатные flows должны записывать court.

## Availability/conflicts

Конфликт определяется в контексте:

```text
venue
+ venue_court
+ time overlap
+ WHOLE / HALF_A / HALF_B
```

Правила внутри одного court не меняются:

- `WHOLE` конфликтует с `WHOLE`, `HALF_A`, `HALF_B`;
- `HALF_A` конфликтует с `WHOLE`, `HALF_A`;
- `HALF_B` конфликтует с `WHOLE`, `HALF_B`;
- `HALF_A` и `HALF_B` могут сосуществовать.

Bookings разных courts одного Venue не конфликтуют.

Legacy booking с `venue_court_id = NULL` конфликтует со всеми courts этого Venue, чтобы migration не создала ложную доступность.

## Physical halves capability

Физическая возможность деления определяется `VenueCourt.supports_halves`.

`VenueBookingPolicy.allows_halves` остаётся коммерческим разрешением. Для half booking должны одновременно выполняться оба условия:

```text
court.supports_halves = true
AND policy.allows_halves = true
```

## Rental quote/request

Quote выбирает конкретный court. Если court явно не передан, используется primary court.

Immutable quote snapshot schema v2 фиксирует:

- `venue_id`;
- `venue_court_id`;
- court name/alias на момент quote;
- scope/time/pricing/policy.

При создании заявки `venue_court_id` переносится из quote в booking.

## Events

- обычное создание Event использует выбранный `venue_court_id` либо primary court;
- legacy event booking получает тот же court;
- Event, созданный после подтверждения rental booking, наследует court из booking;
- изменение свободного Event может менять court вместе с venue/time с повторной проверкой availability;
- booking-backed Event нельзя произвольно переносить между courts в обход booking lifecycle.

## Rental UI/projection

Страница расчёта аренды позволяет выбрать court при нескольких halls. Half options показываются только для court с `supports_halves=true`.

Availability projection принимает `venue_court_id` и возвращает busy intervals выбранного court; NULL legacy bookings остаются venue-wide blockers.

## Security/consistency

- court всегда проверяется на принадлежность выбранному Venue;
- soft-deleted court нельзя выбрать;
- чужой court не должен позволять обойти conflict checks;
- существующие privacy rules Event не меняются.

## Acceptance criteria

- одновременные WHOLE bookings разных courts разрешены;
- WHOLE booking блокирует пересечение только внутри своего court;
- HALF_A/HALF_B сохраняют старую conflict matrix внутри court;
- half запрещён, если selected court не поддерживает halves;
- quote/request/event сохраняют один и тот же court;
- legacy NULL court безопасно конфликтует со всеми courts;
- старые callers без court продолжают работать через primary court;
- regression suite и CI зелёные.
