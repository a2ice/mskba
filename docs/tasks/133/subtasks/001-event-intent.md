# 001 — Event intent и автоматическое создание

## Цель

Сохранить параметры будущего мероприятия рядом с новой бронью и создать Event ровно один раз после подтверждения.

## Краткое описание

Добавить one-to-one event intent, application orchestration и listener для `VenueBookingConfirmed`. Расширить существующий handler создания Event из брони всеми параметрами standalone-игры и отложенной Telegram-публикацией.

## Статус

Выполнено.

## Результат выполнения

Добавлен one-to-one event intent с уникальными `venue_booking_id` и `request_key`.
Событие `VenueBookingConfirmed` материализует Event ровно один раз, а Telegram-публикации
ставятся в очередь после создания мероприятия.
