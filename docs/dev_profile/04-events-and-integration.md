# 04. События, слушатели, стратегии и внешние эффекты

[← Оглавление](README.md) · [Далее →](05-atomicity-idempotency.md)

## Event / Listener человеческим языком

**Event** сообщает: «бизнес-факт произошёл». **Listener** говорит: «когда случится этот факт, выполни отдельную реакцию». Изменение контакта не должно напрямую знать, как начислять права, отправлять письма и искать дубли.

~~~text
Контакт успешно подтверждён
        │ event: UserContactConfirmed
        ├── GrantOperationalPermissionsAfterContactConfirmed
        ├── ScanUserDuplicatesAfterContactConfirmed
        └── CreateContactConfirmedNotification
                 │ event: UserNotificationCreated
                 ├── BroadcastUserNotificationCreated → Reverb
                 ├── QueueUserNotificationEmailDelivery
                 └── QueueUserNotificationTelegramDelivery
~~~

**Проверено:** [событие](../../app/Modules/Contact/Domain/Events/UserContactConfirmed.php), [Identity provider](../../app/Modules/Identity/Infrastructure/Providers/IdentityCanonicalizationServiceProvider.php), [Notification provider](../../app/Modules/Notification/Infrastructure/Providers/NotificationServiceProvider.php), [Telegram provider](../../app/Modules/Telegram/Infrastructure/Providers/TelegramServiceProvider.php).

## Отличия Event / Listener / Job / Queue

| Термин | Роль | Важная оговорка |
| --- | --- | --- |
| Event | Факт, содержащий минимум контекста | Сам ничего не «запускает в фоне» |
| Listener | Подписанная реакция | По умолчанию может выполняться синхронно |
| Job | Единица фоновой работы | Должен быть устойчив к повторам и сбоям |
| Queue | Механизм доставки job | Работает через выбранный драйвер (database/Redis и др.) |
| Observer | Реакция на lifecycle ORM-модели | Не заменяет ясное предметное событие |

**Важно:** вызов event(...) не означает, что событие переживёт rollback. Для критичного сценария аренды есть Transactional Outbox.

## Критичный пример: Transactional Outbox для аренды

~~~text
DB::transaction
  ├── INSERT venue_bookings
  ├── INSERT venue_booking_transitions
  └── INSERT venue_booking_outbox_messages (pending)
COMMIT
  └── afterCommit: dispatch job
        └── dispatcher → event/listeners → published
              └── при сбое pending + retry/backoff
~~~

**В коде:** [VenueBookingOutbox](../../app/Modules/VenueBooking/Application/Services/VenueBookingOutbox.php) сохраняет запись и вызывает DB::afterCommit; [VenueBookingOutboxDispatcher](../../app/Modules/VenueBooking/Application/Services/VenueBookingOutboxDispatcher.php) забирает pending, ставит processing, публикует и повторяет с задержкой при ошибке.

**Зачем:** обычный «сохрани бронь → отправь сообщение» может потерять отправку при падении процесса после COMMIT. Outbox сохраняется **в той же транзакции** с бронью. **Ограничение:** это доставка «как минимум один раз»; получатель обязан быть идемпотентным. Не обещаем exactly-once при внешнем эффекте.

## Strategy: варианты без нагромождения if

Есть интерфейс [ContactVerificationStrategy](../../app/Modules/Contact/Application/Contracts/ContactVerificationStrategy.php) с supports(Contact) и start(Contact). [EmailContactVerificationStrategy](../../app/Modules/Contact/Application/Strategies/EmailContactVerificationStrategy.php) отвечает за email: cooldown, генерацию и хеширование кода, доставку письма, срок жизни и обработку ошибки. Добавление новых каналов возможно отдельными стратегиями, без роста огромного switch в контроллере.

**Особенность:** стратегия проверяет реальность транспорта: если логгер/нулевой mail transport, не создаёт фиктивный отправленный код; старый код отменяет после принятой доставки нового.

Аналогичный паттерн — [RewardMechanism](../../app/Modules/Rewards/Application/Contracts/RewardMechanism.php) + [RewardMechanismRegistry](../../app/Modules/Rewards/Application/Services/RewardMechanismRegistry.php): механизм для прямого реферала, второй линии, первого одобрения площадки. Ядро RewardsEngine работает по контракту.

## Event и бизнес-логика

**Тонкость:** событие лучше называть в прошедшем времени (UserContactConfirmed), команду — глаголом (ConfirmContact). Событие — факт, который уже подтверждён. Не использовать события только чтобы скрыть линейный вызов без причины: от этого становится сложнее проследить транзакции и ошибки.

## Проверка собеседующего

**«После event listener упал — что с основной операцией?»** Зависит от синхронности слушателя, места вызова и transaction boundaries. В критичных сценариях Outbox отделяет фиксацию бизнес-факта от доставки; retry обрабатывает временный сбой.

**«Можно дважды обработать одно событие?»** Да. При at-least-once нужны уникальные ключи или журнал обработанных message ID.

**«Event sourcing?»** Нет, Outbox и история переходов **не означают**, что вся система построена на Event Sourcing. Основное состояние хранится в обычных таблицах.

[Далее — гарантии и конкурентные запросы](05-atomicity-idempotency.md)
