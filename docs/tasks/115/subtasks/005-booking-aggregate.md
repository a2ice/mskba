# 005 — Агрегат и жизненный цикл бронирования

## Цель
Ввести отдельный от Event агрегат аренды с явным автоматом состояний.

## Статус
Выполнена в `feature/115`.

## Доменные изменения
`REQUESTED` не занимает слот; `HELD` и `CONFIRMED` занимают; `REJECTED`, `CANCELLED`, `EXPIRED` терминальны. Event появляется только после `CONFIRMED`.

## Миграции
Bookings, status history, parties, quote snapshot, hold/payment deadlines, version для optimistic checks; индексы venue/status/time.

## Handlers и сервисы
Request, Hold/Approve, Reject, Cancel, Confirm; все переходы через aggregate/use-case, не через прямой update модели.

## Права
Заявитель управляет своей REQUESTED; коммерческая сторона принимает; confirm требует выполненных условий оплаты.

## UI/API
Статус, следующий допустимый шаг и причины недоступности выдаются сервером.

## События и jobs
BookingRequested/Held/Confirmed/Rejected/Cancelled/Expired после commit.

## Тесты
Таблица допустимых переходов, повтор команды, терминальные состояния, rollback истории.

## Обратная совместимость
Legacy `PENDING` читается адаптером и мигрируется отдельно без смены смысла.

## Критерии приёмки
- невозможный переход отклоняется доменом;
- Event не является бронью;
- история состояния непрерывна и неизменяема.

## Результат выполнения

- существующая таблица `venue_bookings` расширена без переопределения legacy
  семантики: старый `PENDING` продолжает занимать слот, а новый flow помечается
  `flow=rental` и начинается с не занимающего ресурс `REQUESTED`;
- nullable `event_id` отделяет коммерческую заявку от Event; добавлены applicant,
  policy/quote references, неизменяемый quote snapshot, payment state, hold и
  protection deadlines, optimistic version и индексы выборок;
- отдельный `App\Modules\VenueBooking\Domain\Models\VenueBooking` является
  корнем нового агрегата, при этом legacy Event model и маршруты остаются
  совместимыми с теми же строками;
- `VenueBookingLifecycle` реализует разрешённые переходы `REQUESTED →
  HELD|REJECTED|CANCELLED`, `HELD → CONFIRMED|CANCELLED|EXPIRED` и допустимую
  отмену `CONFIRMED`; прямое изменение статуса Eloquent-модели блокируется;
- Request/Accept/Reject/Cancel/Confirm/Expire handlers повторно проверяют feature
  flag, права и optimistic version, выполняются транзакционно с порядком lock
  `venue → booking` и публикуют доменные события after commit;
- timeline в `venue_booking_transitions` append-only и имеет ровно одну запись
  на версию агрегата; стороны заявки фиксируются в `venue_booking_parties`;
- повторный Request с тем же quote возвращает прежнюю заявку, платная бронь не
  подтверждается без payment state `CONFIRMED`, а терминальные состояния не
  принимают новые команды;
- account UI/JSON API возвращает статус, версию, серверно вычисленные доступные
  действия и машиночитаемые причины запрета; client-supplied status/event_id
  игнорируются, IDOR закрыт application authorization.

Полная проверка пересечений при переходе в `HELD` намеренно относится к 006.
До неё feature flag остаётся выключенным по умолчанию. Это не оставляет
production race: новый flow недоступен, а legacy `PENDING/CONFIRMED` продолжает
использовать прежний conflict checker.
