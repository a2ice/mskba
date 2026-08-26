# 018 — Платёжный порт и будущий ledger

## Цель

Определить безопасную границу платёжной интеграции: сейчас поддержать внешнюю ручную оплату, а позднее подключить провайдера без переписывания booking aggregate и без преждевременного внедрения внутреннего кошелька.

## Статус

Выполнена в `feature/115`.

Существующая payment attempt из 013 расширена provider/idempotency/merchant-полями и реализует persistence для intent/attempt без преждевременного ledger. `PaymentProviderPort` отделяет booking от SDK, а `ExternalManualPaymentAdapter` сохраняет прежний flow и принципиально не принимает webhook. Webhook проверяется адаптером, дедуплицируется по provider event ID, сверяет booking, сумму, валюту и merchant, хранит только hash и allowlist safe payload. Подтверждение идемпотентно и меняет только payment state; booking подтверждается прежней отдельной командой. Reconciliation использует distributed lock и те же блокировки aggregate.

## Доменные изменения

- Ввести абстракцию `PaymentIntent`/`PaymentAttempt`, связанную с booking, суммой snapshot, валютой и provider reference.
- Статусы платежа не равны статусам брони; только подтверждённый разрешённым способом результат может инициировать booking-команду.
- CLAIMED/«я оплатил» остаётся пользовательским заявлением, не подтверждением.
- Ledger, баланс пользователя, возвраты и split payments вывести за рамки первого релиза, сохранив расширяемые идентификаторы.

## Миграции

- Добавить payment intents/attempts с уникальными provider id и idempotency key.
- Хранить raw provider payload отдельно/ограниченно, без секретов и платёжных реквизитов.
- Внешние ручные подтверждения из 013 оформлять как отдельный adapter/status, не подделывая webhook.

## Handlers и сервисы

- Порт `PaymentProviderPort`: create intent, query status, cancel/expire intent, verify webhook.
- Адаптер `ExternalManualPaymentAdapter` для текущего сценария и fake adapter для тестов.
- Будущий provider adapter должен проверять подпись, сумму, валюту, merchant и принадлежность booking.
- `HandlePaymentWebhook` идемпотентен и устойчив к повторным и переставленным событиям.
- Reconciliation job сверяет зависшие intent с провайдером.

## Права

- Создать intent может только сторона бронирования с соответствующим правом.
- Ручное подтверждение владельцем требует права принимать оплату и audit trail.
- Webhook не использует пользовательскую сессию и проходит отдельную криптографическую проверку.
- Секреты доступны только runtime/deploy-контуру.

## UI/API

- Пользователь видит способ, сумму, валюту, срок и различие «заявлено»/«подтверждено».
- Страница возврата от провайдера не считается доказательством оплаты до server-to-server проверки.
- Повторное открытие страницы показывает текущий intent без создания дубликата.
- Владелец видит источник подтверждения и provider reference в маскированном виде.

## События и jobs

- `PaymentIntentCreated`, `PaymentClaimed`, `PaymentConfirmed`, `PaymentFailed`, `PaymentExpired`.
- Webhook сохраняется/обрабатывается идемпотентно; booking-команда вызывается после проверки.
- Reconciliation и expiry jobs используют distributed lock и ограниченный retry.

## Тесты

- Контрактные тесты порта на fake/manual adapters.
- Повторный, запоздалый и out-of-order webhook.
- Неверная подпись, сумма, валюта, merchant и booking reference.
- Concurrent webhook/reconciliation подтверждает бронь один раз.
- Проверка отсутствия секретов и чувствительных payload в логах.

## Обратная совместимость

- Первый релиз продолжает внешний payment flow из 013 через adapter.
- Подключение провайдера выполняется конфигурацией/feature flag по площадке или договору.
- Booking aggregate зависит от результата порта, а не от SDK конкретного провайдера.

## Критерии приёмки

- Повторный webhook или refresh не создаёт второй платёж/подтверждение.
- Пользовательский CLAIMED не подтверждает бронь.
- Провайдер заменяем без изменения доменной модели бронирования.
- Ошибки и reconciliation наблюдаемы, а секреты не попадают в БД и логи.
