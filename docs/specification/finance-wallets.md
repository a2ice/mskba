# Finance: кошельки и журнал транзакций

## Оглавление

- [Назначение](#назначение)
- [Граница контекста](#граница-контекста)
- [Модель кошелька](#модель-кошелька)
- [Real и bonus](#real-и-bonus)
- [Журнал транзакций](#журнал-транзакций)
- [Атомарность и идемпотентность](#атомарность-и-идемпотентность)
- [Политики списания](#политики-списания)
- [Владельцы и права](#владельцы-и-права)
- [Rollout owner-кошельков](#rollout-owner-кошельков)
- [Интеграция с прайс-листом](#интеграция-с-прайс-листом)
- [Внешние платежи и будущие сценарии](#внешние-платежи-и-будущие-сценарии)

## Назначение

\`App\Modules\Finance\` — единый bounded context для внутреннего учёта стоимости
в MSKBA. Он даёт другим контекстам безопасную операцию «зачислить» или «списать»
и сохраняет происхождение каждого движения.

Термин \`real\` здесь означает происхождение остатка из реальных денежных
поступлений. Он не означает, что MSKBA самостоятельно является банком или
платёжным провайдером.

## Граница контекста

Finance владеет:

- \`wallets\`;
- \`wallet_operations\`;
- \`wallet_ledger_entries\`;
- правилами баланса, списания и идемпотентности;
- проверкой того, кто вправе распоряжаться кошельком предметного owner-а.

Finance не владеет ценой товара/услуги, бронированием, реферальным условием,
эквайрингом или банковским settlement. Источник вызывает Finance и передаёт
operation type, reference и безопасные metadata.

## Модель кошелька

Owner задаётся нейтральной парой \`owner_type + owner_id\`. В первой версии
разрешённые owner types:

- \`user\`;
- \`team\`;
- \`event\`;
- \`venue\`.

В БД не хранится PHP class name Eloquent morph-типа: это не связывает
долговременную финансовую схему с namespace/именем модели.

Связь owner -> wallet является 1:N. Текущая application API создаёт
\`type=main, currency=RUB\`, но уникальность
\`owner_type + owner_id + type + currency\` позволяет добавить новые назначения
кошельков или валюты без переделки базовой связи.

Для user owner идентификатор канонизируется через Identity: alias-дубликат не
получает самостоятельный денежный кошелёк.

## Real и bonus

В \`wallets\` хранятся два материализованных остатка:

- \`real_balance_minor\`;
- \`bonus_balance_minor\`.

Оба значения — integer в минимальной денежной единице. Для RUB 10000 означает
100,00 ₽. Float/decimal arithmetic в application logic не используется.

Пользовательский общий баланс:

\`\`\`text
available_total = real_balance_minor + bonus_balance_minor
\`\`\`

Но система всегда знает происхождение каждой части. Реферальная награда не
создаёт третий кошелёк: это \`referral_reward\`, зачисленный в bonus.

## Журнал транзакций

Ledger — журнал финансовых движений. \`wallet_ledger_entries\` append-only:
обновление и удаление строк запрещены моделью.

Каждая запись содержит:

- \`wallet_id\`;
- \`operation_id\`;
- \`balance_type = real|bonus\`;
- signed \`amount_minor\`;
- \`balance_after_minor\`.

\`wallet_operations\` группирует одно бизнес-действие. Например, списание
1200 ₽ при bonus=500 ₽ и real=1000 ₽ образует одну operation и две ledger rows:
-50000 bonus и -70000 real.

Ledger позволяет аудит/сверку, но обычное чтение баланса не должно суммировать
всю историю. \`wallets.*_balance_minor\` — материализованный текущий остаток,
который изменяется в той же транзакции, что и ledger.

## Атомарность и идемпотентность

Любая мутация:

1. открывает DB transaction;
2. блокирует wallet через \`lockForUpdate\`;
3. получает/создаёт operation по \`idempotency_key\`;
4. сравнивает hash канонического payload;
5. меняет материализованный остаток;
6. добавляет ledger rows;
7. помечает operation завершённой;
8. commit.

Повтор \`idempotency_key\` с тем же payload возвращает сохранённую operation.
Тот же ключ с другим payload — ошибка. Таким образом retry банковского webhook,
queue job или двойной submit не должны начислить стоимость второй раз.

## Политики списания

Фундамент поддерживает:

- \`bonus_then_real\` — default для внутренних услуг MSKBA;
- \`real_only\`;
- \`bonus_only\`.

Политика — параметр use case, а не свойство происхождения денег. Поэтому позже
можно запретить bonus конкретному классу услуг без миграции кошельков.

Недостаточный разрешённый остаток отменяет всю DB transaction: частичного
списания нет.

## Владельцы и права

Кошелёк сущности принадлежит сущности. User с permission — оператор, а не owner
денег.

Контекстные capabilities:

- Team: \`team.wallet.manage\`;
- Event: \`event.wallet.manage\`;
- Venue: \`venue.wallet.manage\`.

\`WalletAccess\` переиспользует существующие ACL:
\`TeamManagementAccess\`, \`EventManagementAccess\`, \`VenueCommercialAccess\`.
Для собственного user wallet требуется совпадение канонической identity.

Event finance permission — opt-in capability. Старый fallback «дать все права,
если permissions не переданы» специально использует
\`defaultAssignmentPermissions()\`, которая исключает новое финансовое право.
Так добавление enum case не расширяет существующую семантику скрытым образом.

Для Venue OWNER новое право входит в шаблон будущих owner snapshots.
Существующие contract permission snapshots автоматически не расширяются.
MANAGER может получить \`venue.wallet.manage\` явно; это не default.

## Rollout owner-кошельков

\`config/finance.php\` содержит owner-level runtime gates:

- user — enabled;
- team — disabled;
- event — disabled;
- venue — disabled.

Это технический safety guard первой итерации. Он не заменяет ACL: для операции
нужны и включённый owner type, и право оператора на уровне вызывающего use case.

Будущий UI-переключатель доступен только superadmin и перед включением
Team/Event/Venue должен явно предупреждать, что активируется финансовое
поведение предметной сущности. До реализации такого UI production defaults для
этих owner types остаются false.

## Интеграция с прайс-листом

Прайс-лист — отдельный bounded context/модуль. Он отвечает за SKU/услугу,
категорию, текущую цену и доступность. Finance не должен импортировать
административную модель прайс-листа внутрь Domain.

Будущий purchase flow:

\`\`\`text
Catalog/Service -> resolves price
Application purchase use case -> freezes amount/reference
Finance -> debits wallet idempotently
Service context -> delivers result
\`\`\`

Если нужен compensated flow, refund создаётся отдельной Finance operation со
ссылкой на исходную покупку; ledger не редактируется задним числом.

## Внешние платежи и будущие сценарии

Не входят в первую итерацию:

- P2P transfer wallet A -> wallet B;
- вывод;
- превращение bonus в выводимые средства;
- settlement с площадкой или продавцом;
- банковский acquiring adapter.

Кейс «A оплачивает внутреннюю услугу для B» в будущем не требует P2P: покупка
может списать wallet A, сохранив B как beneficiary в reference/metadata.

Когда появится реальный payment provider, его SDK/API должен находиться за
application port/Infrastructure adapter (ACL). Подтверждённый внешний платёж
создаёт идемпотентную Finance operation; банковский provider payload/секреты не
должны попадать в ledger metadata.

Расчёты с внешними поставщиками не следует моделировать как обычный перенос
bonus/real между owner-wallets до отдельного продуктового и юридического
решения.
