# 310 — Ручное оформление внешней брони ответственными по заявке MSKBA

## Цель
Бронь создаётся в MSKBA без createBooking на внешнем сайте. Представитель/агент площадки с назначенными контрактными правами вручную проверяет и оформляет её снаружи, согласовывает с пользователем и фиксирует итог.

## Объём
- Переиспользовать RequestVenueBookingHandler, VenueBookingLifecycle, ListOwnerBookingInbox, permission rental.bookings.view/decide, existing conversations/notifications/outbox, immutable Quote.
- Основные статусы REQUESTED -> HELD -> CONFIRMED / REJECTED / CANCELLED / EXPIRED сохранить. HELD — удержание только на MSKBA, никогда не обещать внешнюю бронь.
- Отдельный external fulfillment status: not_started/checking/processing/confirmed/failed; external ref/source, inspected_at, representative actor.
- На принятие/подтверждение/отклонение/отмену добавить обязательный или опциональный (по типу действия) комментарий, сохраняемый в историю и видимый участникам по правам. Например: «подтверждено — оплату обещал занести завтра», «отклонено — отказался от предоплаты».
- **Разделить факт внешней брони и факт оплаты**: пользователь может оплатить наличными, СБП напрямую, позже или иным способом вне MSKBA; решение ответственного допускает CONFIRMED без выдуманного payment CONFIRMED. Данные об оплате — свободный комментарий + отдельное состояние «не подтверждена / сообщено об оплате / подтверждена вне MSKBA» при необходимости; не считать средства полученными MSKBA.
- Для конкретной политики оплаты явно разрешить external_manual settlement и собственные guards; не обходить existing payment gate глобально.
- При отправке заявки уведомлять owner и представителей с разрешениями, dedup на пользователя; исполнитель может взять в работу. Переписка MSKBA — основной журнал, внешние каналы допустимы без объявления автоматического подтверждения.
- Проверка конфликтов внутренних/внешних slots, race и версии booking, отказ и альтернативные интервалы, retries/notification delivery.
- Не создавать настоящую внешнюю бронь автоматически; createBooking в адаптере остаётся выключен.

## Acceptance
User -> request -> representatives notifications -> assign -> external check -> comments -> manual confirm without MSKBA payment; delayed cash/SBP/promise, rejection reason, immutable history, 403, concurrent accepts, stale availability, guest. CI green, merge dev, инструкция реального E2E теста для пользователя, приёмка. main/prod не менять.
