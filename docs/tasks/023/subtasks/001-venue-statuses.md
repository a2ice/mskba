# 001 - Очистить статусы площадок

## Цель

Оставить в `VenueStatusEnum` только жизненные статусы площадки: `unconfirmed`, `confirmed`, `blocked`.

## Краткое описание

Удаление переводится на soft delete, причина блокировки хранится в `venues.status_info`, а duplicate-состояние переносится из статуса площадки в связи `canonical_venue_id` и `venue_duplicates`.

## Статус

Выполнено.

## Результат выполнения

`VenueStatusEnum` очищен до `unconfirmed`, `confirmed`, `blocked`. В `venues` добавлены `status_info` и soft delete. Историческая миграция duplicate-статуса переведена на строковые литералы, чтобы `migrate:fresh` не зависел от удаленного enum-case.
