# 218 — Бонусные переводы между пользовательскими кошельками и уведомления

## Оригинальное описание

Разрешить пользователям переводить бонусные средства между своими user-wallets.
Real и bonus считаются разными счетами: первая версия открывает только bonus,
но архитектура должна позволять позже включить real-переводы отдельным runtime
gate без переделки ledger.

Для production smoke дать текущему superadmin одноразовое начисление 10 000 ₽
на bonus-баланс, чтобы проверить цепочку superadmin -> @olsen -> тестировщик.

Получатель перевода должен получить системное финансовое уведомление и realtime
toast. Отправителю persistent-уведомление не требуется: результат операции сразу
виден в UI и в истории кошелька.

## Реализация

- одна `wallet_operation=user_transfer` на весь перевод;
- две immutable ledger rows в одной транзакции: debit sender + credit recipient;
- блокировка обоих кошельков в стабильном порядке;
- idempotency key защищает от двойного submit;
- self-transfer и transfer между alias одной identity запрещены;
- получатель задаётся точным `@nickname` или логином;
- получатель должен быть подтверждённым активным пользователем;
- bonus transfer включён по умолчанию;
- real transfer оставлен выключенным;
- Finance notification создаётся только получателю;
- существующий Reverb notification pipeline показывает toast;
- superadmin bootstrap bonus доступен один раз и сам идемпотентен.

## UI

На `/account/wallet` добавляется форма:

- получатель;
- сумма;
- текущий доступный bonus;
- кнопка перевода.

В истории перевод для отправителя подписывается `→ @recipient`, для получателя
`← @sender`.

Для superadmin до первого smoke-начисления показывается отдельная кнопка
«Начислить 10 000 ₽ бонусами». После успешного начисления она исчезает.

## Не входит

- transfer real-баланса в production;
- P2P между Team/Event/Venue;
- вывод;
- эквайринг;
- комиссии и лимиты;
- Purchase/Order.

## Проверка

- атомарность и сохранение общей суммы bonus;
- недостаточный баланс;
- idempotent retry;
- запрет self-transfer;
- runtime gate real transfer;
- UI transfer по `@nickname`;
- notification только получателю;
- one-time superadmin bootstrap;
- полный CI и frontend build.

## Статус

Выполнено и влито в `main` через PR #248,
merge commit `631eff7b378207d588d28ce63e9354c00d001b6a`.
