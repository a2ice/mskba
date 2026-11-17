# 05. Атомарность, идемпотентность и гонки данных

[← Оглавление](README.md) · [Далее →](06-data-postgresql.md)

## Четыре разных понятия

| Понятие | Вопрос | Инструмент |
| --- | --- | --- |
| **Atomicity** | Пройдут ли все изменения вместе или откатятся вместе? | DB::transaction |
| **Idempotency** | Что будет, если клиент повторит тот же запрос? | idempotency_key, receipt, hash |
| **Concurrency control** | Что если **два** человека выполняют действие одновременно? | row locks, уникальные ограничения, optimistic version |
| **Reliable delivery** | Что если сервис упал **после COMMIT**, до отправки? | Transactional Outbox + retry |

## Кейс 1: регистрация как одна транзакция

[RegisterUserHandler](../../app/Modules/Identity/Application/UseCases/RegisterUserHandler.php) создаёт User, участие, профиль игрока и согласие на ПДн внутри DB::transaction. Если сохранение обязательного элемента не удастся — транзакция откатится. Проверка дубликатов запускается после транзакции.

**Зачем:** пользователь не должен иметь частично созданную, формально успешную регистрацию.

## Кейс 2: идемпотентная команда бронирования

[IdempotentVenueBookingCommand](../../app/Modules/VenueBooking/Application/Services/IdempotentVenueBookingCommand.php):

~~~text
Клиент → command + idempotency_key + payload
  ↓ canonical JSON → SHA-256(payload)
DB transaction
  ├── insertOrIgnore receipt
  ├── lockForUpdate receipt
  ├── тот же ключ + другой payload? → conflict
  ├── completed? → вернуть прежнюю бронь
  ├── иначе выполнить callback
  └── записать booking_id + completed
COMMIT
~~~

Уникальность receipt в БД — защита от race condition; hash — от повторного использования ключа с другим смыслом.

**Не путай:** transaction защищает частично записанное состояние, но не препятствует выполнению **второго** запроса после первого коммита. Идемпотентность решает именно это.

## Кейс 3: конкурентная бронь слота

[LockedVenueBooking](../../app/Modules/VenueBooking/Application/Services/LockedVenueBooking.php) получает блокировки в стабильном порядке: площадка → проверка конфликтов → бронь; [DeadlockRetrier](../../app/Modules/VenueBooking/Application/Services/DeadlockRetrier.php) может повторить целиком транзакционную попытку при распознанном deadlock. [VenueBookingLifecycle](../../app/Modules/VenueBooking/Domain/Services/VenueBookingLifecycle.php) валидирует разрешённые переходы и ожидаемую optimistic_version.

~~~text
A и B хотят один и тот же слот
A: захватил lock → проверил конфликты → записал бронь → commit
B: ждал lock → повторно проверил состояние → отказ/актуальный результат
~~~

**Пессимистическая блокировка** предотвращает конфликт до записи (SELECT ... FOR UPDATE). **Оптимистическая версия** обнаруживает устаревшее ожидание клиента. Оба инструмента могут дополнять друг друга.

## Кейс 4: деньги без двойного начисления

[IdempotentWalletMutation](../../app/Modules/Finance/Application/Services/IdempotentWalletMutation.php) блокирует кошелёк, пытается зарегистрировать операцию по уникальному idempotency key, сверяет hash запроса, затем выполняет изменение баланса + ledger entries одной транзакцией.

[RewardEngine](../../app/Modules/Rewards/Application/Services/RewardEngine.php) использует **бизнес-факт** (тип + ключ), запись reward grant и ключ вида reward-grant-ID при вызове кошелька. Это два уровня защиты: один факт не должен дать два гранта, один грант не должен вызвать два зачисления.

**Почему суммы в minor units?** Целые копейки/центы избегают неточности float при учёте денежных средств.

## Кейс 5: модерация изменения профиля — WIP

В локальном незакоммиченном [ProfileChangeRequestService](../../app/Modules/Identity/Application/Services/ProfileChangeRequestService.php) используется транзакция и lockForUpdate на User/Request; при approve проверяется, что снимок исходных данных не устарел. Если профиль уже изменился, запрос отклоняется вместо молчаливого перетирания новых данных. Описывать это на интервью только как текущую локальную разработку, пока не опубликовано.

## Что спрашивают

**«Не проще Cache::lock?»** Для денежных и критичных БД-инвариантов первичный источник истины — PostgreSQL и ограничения. Redis lock может помогать внешней координации, но не заменяет SQL transaction при сбоях.

**«Можно ли использовать request UUID один раз и не сохранять?»** Нет: без персистентного receipt повтор после рестарта не распознать.

**«Зачем Outbox, если есть afterCommit?»** afterCommit не вызывается до COMMIT, но при аварии **после COMMIT и до выполнения callback** событие всё равно можно потерять. Durable запись Outbox позволяет добрать его позже.

**«Какие ограничения остаются?»** Сложность lock ordering, deadlock/retry, возможная повторная обработка outbox, мониторинг залежавшихся pending, миграции уникальных ключей. Гарантии нужно проверять конкурентными тестами.

**Проверить тестами:** [VenueBookingLifecycleTest](../../tests/Feature/VenueBooking/VenueBookingLifecycleTest.php), [WalletLedgerTest](../../tests/Feature/Finance/WalletLedgerTest.php), [RewardMechanismPayoutTest](../../tests/Feature/Rewards/RewardMechanismPayoutTest.php).
