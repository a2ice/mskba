# 005 — Агрегат и жизненный цикл бронирования

## Цель
Ввести отдельный от Event агрегат аренды с явным автоматом состояний.

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

