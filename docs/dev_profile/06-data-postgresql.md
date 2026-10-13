# 06. PostgreSQL, нормализация и оптимизация

[← Оглавление](README.md) · [Далее →](07-redis-and-queues.md)

## Почему PostgreSQL

MSKBA хранит множество связанных сущностей, где важны **ACID-транзакции, ссылочная целостность, уникальность и предсказуемые конкурентные обновления**: аккаунты, права, команды, площадки, бронь, кошельки. В [Compose](../../compose.yaml) предусмотрен PostgreSQL 17. **Не утверждай**, что Redis или JSON-поле заменяют реляционную БД.

## Нормализация на предметном примере

Плохая условная таблица:

| booking_id | venue_name | owner_email | member_ids | bonus_balance |
| --- | --- | --- | --- | --- |
| 5 | Зал А | a@example.com | 12,17,23 | 150 |

Здесь дублируются данные площадки и владельца, список участников трудно индексировать, сумма смешана с бронированием. Более устойчивый дизайн (**упрощённая концепция**):

~~~text
venues          (id, ...)
venue_bookings  (id, venue_id FK, status, ...)
booking_parties (id, booking_id FK, user_id FK, role)
users           (id, ...)
wallets         (id, owner_type, owner_id, ...)
wallet_operations / wallet_ledger_entries
~~~

В MSKBA похожее разделение реально существует в моделях [VenueBooking](../../app/Modules/VenueBooking/Domain/Models/VenueBooking.php), [VenueBookingParty](../../app/Modules/VenueBooking/Domain/Models/VenueBookingParty.php), [Wallet](../../app/Modules/Finance/Domain/Models/Wallet.php) и [WalletLedgerEntry](../../app/Modules/Finance/Domain/Models/WalletLedgerEntry.php).

**1NF:** одно атомарное значение, нет массива участников в строке. **2NF/3NF:** данные пользователя и площадки живут в своих сущностях, зависимостях не дублируются. **Прагматичный компромисс:** в бронировании сохраняется **quote_snapshot**, то есть неизменяемая историческая копия условий расчёта. Это осознанная денормализация: условия аренды могут поменяться после создания брони, но прежнюю договорённость нужно восстановить.

## Индексы и constraints

В [миграции reward_grants](../../database/migrations/2026_10_06_160100_create_reward_grants_table.php) есть FK на reward, version, user и wallet_operation; составной unique по reward_id + business_fact_type + business_fact_key + recipient_user_id; индекс по status + created_at. Благодаря unique два обработчика не могут записать один и тот же грант для того же получателя даже при гонке.

**Разница:**
- **INDEX** ускоряет фильтры/сортировки/joins, но не запрещает дубликаты.
- **UNIQUE** обеспечивает бизнес-инвариант на уровне БД (и создаёт пригодный для поиска индекс).
- **FOREIGN KEY** запрещает недопустимые ссылки.
- **CHECK** (когда предусмотрен схемой) ограничивает диапазон/состояние.

Индекс не бесплатный: занимает место, замедляет INSERT/UPDATE и должен соответствовать реальным WHERE/JOIN/ORDER BY.

## N+1 — типовой антипаттерн

~~~php
// Концепция: плохо — для каждой брони отдельный запрос к площадке.
foreach (VenueBooking::all() as $booking) {
    echo $booking->venue->name;
}

// Лучше — eager loading и ограничение набора.
VenueBooking::query()->with('venue')->limit(100)->get();
~~~

В проекте [VenueSearchCache](../../app/Modules/Venue/Application/Services/VenueSearchCache.php) сразу загружает tags, location.address.cityDirectory и metroStations. В [ShowVenueHandler](../../app/Modules/Venue/Application/UseCases/ShowVenueHandler.php) используется withCount — не нужно по отдельности загружать каждую коллекцию только для подсчёта.

**Проверь в ответе:** eager loading уменьшает число запросов, но может увеличить память, если подгружать слишком много колонок или записей.

## EXISTS vs тяжелые JOIN — и когда IN нормален

~~~sql
-- Концептуальный запрос проверки права на площадку:
SELECT v.*
FROM venues v
WHERE EXISTS (
  SELECT 1
  FROM contract_memberships cm
  WHERE cm.scope_type = 'venue'
    AND cm.scope_id = v.id
    AND cm.user_id = :user_id
);
~~~

**Почему EXISTS:** отвечает «есть ли хотя бы одна запись?», не размножает строки результата при нескольких membership и может быть выражен как semi-join. **Почему не всегда JOIN:** множественные связи увеличивают кардинальность и потребность в DISTINCT/GROUP BY. **Почему не всегда whereIn:** огромный PHP-массив ID — лишняя передача данных в приложение; подзапрос SELECT может быть лучше.

**В коде:** [VenueMembershipAccess](../../app/Modules/Venue/Application/Services/VenueMembershipAccess.php) использует exists() для точечной проверки и whereHas() для связей. [PublicUserProfileService](../../app/Modules/Identity/Application/Services/PublicUserProfileService.php) использует whereIn с **подзапросом** select('scope_id'), не предварительно загружая все ID. В локальном **WIP** [ProfileChangeRequestNotificationService](../../app/Modules/Identity/Application/Services/ProfileChangeRequestNotificationService.php) есть whereExists для review_permission_cursors; это пример текущей разработки, не production-результат.

**Важно:** whereHas в Eloquent часто уже переводится в SQL EXISTS; «всегда писать ручной SQL вместо whereHas» — неверно.

## Как грамотно оптимизировать

1. Зафиксировать проблему: p95 ответа, количество SQL, частота вызова, размер результата.
2. Посмотреть реальный SQL и параметры; обнаружить N+1/неудачный JOIN/IN.
3. Выполнить **EXPLAIN (ANALYZE, BUFFERS)** на безопасной тестовой выборке (не на тяжёлом production-запросе без оценки).
4. Сравнить estimated и actual rows, sequential/index scans, время сортировки и количество чтений.
5. Добавить точечный индекс, переписать запрос, сократить поля, use eager loading/exists/pagination.
6. Повторно измерить на сопоставимых данных и проверить регрессии.

**Не преувеличивай:** в этом пакете нет проверенного числового бенчмарка «до/после» для MSKBA. Объясняй механизм и указывай, что цифры нужны из метрик.

## Контрольные вопросы

«В чём разница нормализации и денормализации?» «Когда использовать partial/composite index?» «Почему LIMIT без устойчивого ORDER BY плохо для пагинации?» «Что покажет EXPLAIN ANALYZE?» «Почему индекс может не использоваться?» «Как решить вопрос с многими JOIN при сложной ACL?»
