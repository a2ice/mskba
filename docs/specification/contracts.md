# Contracts

Техническая концепция контрактов и контекстных прав доступа.

## Назначение

Контракт в проекте должен описывать подтвержденную или ожидающую подтверждения связь между сущностями системы. Это не только договор на оказание услуг: контракт может фиксировать членство пользователя в команде, управление площадкой, участие команды в событии, бронирование площадки событием или партнерство двух организаций.

## Текущее состояние

В текущей кодовой базе контракт уже используется для доступа пользователя к площадкам.

Существующие таблицы:

- `contracts`;
- `contract_memberships`;
- `contract_relations`;
- `contract_permissions`.

Существующие модели:

- `Contract`;
- `ContractMembership`;
- `ContractRelation`;
- `ContractPermission`.

Существующие права площадки:

- `view`;
- `edit`;
- `edit.schedule`.

`VenueAccessResolver` сейчас использует два источника доступа:

- `venues.created_by_actor_id` как creator/bootstrap shortcut;
- membership permissions через `VenueMembershipAccess`.

## Ограничение holder/provider/customer

В старой схеме был enum `ContractPartyRoleEnum` со значениями:

- `holder`;
- `provider`;
- `customer`.

Эти значения подходят для сервисного или коммерческого договора, но не являются универсальной моделью для всех контрактных связей.

Проблемные примеры:

- игрок в команде не является естественным `holder/provider/customer`;
- сотрудник компании не является естественным `customer/provider`;
- пользователь, управляющий площадкой, скорее имеет access level `manager` или `admin`, чем роль стороны `customer`;
- команда, открывающая другой команде видимость данных, лучше описывается ролями `grantor/grantee`.

Поэтому `holder/provider/customer` нельзя использовать как основу универсального ACL. Текущая чистая схема не использует `contract_parties`; если такие значения понадобятся позже, их область должна быть ограничена relation type, где действительно есть заказчик и исполнитель, например `service`.

## Два семейства контрактов

Текущая модель делит контракты на два семейства:

- `membership_contract`;
- `relation_contract`.

### Membership contract

`membership_contract` описывает связь пользователя с предметной сущностью, в рамках которой пользователь получает роль, уровень доступа или рабочее участие.

Примеры:

- `venue -> user`;
- `event -> user`;
- `team -> user`;
- `company -> user`.

Базовые поля:

- `contract_id`;
- `scope_type`;
- `scope_id`;
- `user_id`;
- `access_level`.

`scope_type` используется как нейтральное название, потому что `venue` и `event` не всегда корректно называть организациями.

Стартовые уровни для `venue` membership:

- `owner`;
- `admin`;
- `manager`;
- `staff`;
- `agent`.

Коммерческий контур аренды использует в той же таблице роли `owner`, `manager`,
`booking_operator`, `finance_viewer`. Старые `admin|staff|agent` сохраняются для
обратной совместимости карточки площадки и сами по себе не дают коммерческих
прав. Коммерческие capabilities хранятся в обычном permission snapshot:
`rental.memberships.manage`, `rental.policy.manage`, `rental.bookings.view`,
`rental.bookings.decide`, `rental.payments.view`, `rental.payments.confirm`.

`VenueCommercialAccess` не использует creator/bootstrap fallback. Он проверяет
только активный Contract permission либо подтверждённый superadmin override.
Поэтому открытая ранее сессия теряет отозванное право на следующей серверной
команде без ожидания cache/session refresh.

`player` не входит в стартовый набор для `venue` membership. Игровое участие пользователя должно моделироваться через событие, команду, тренировку, бронирование или отдельную заявку.

### Relation contract

`relation_contract` описывает связь сущности с сущностью.

Примеры:

- `event -> venue`;
- `team -> venue`;
- `team -> team`;
- `company -> event`;
- `venue -> team`.

Базовые поля:

- `contract_id`;
- `relation_type`;
- `left_type`;
- `left_id`;
- `left_role`;
- `right_type`;
- `right_id`;
- `right_role`.

`relation_type` должен описывать смысл связи, а не только пару типов сущностей.

Возможные relation types:

- `booking`;
- `rental`;
- `partnership`;
- `affiliation`;
- `participation`;
- `hosting`;
- `home_base`;
- `visibility_share`;
- `management`;
- `representation`;
- `sponsorship`;
- `service`;
- `invitation`.

Роли сторон задаются policy конкретного `relation_type`:

- `booking`: `requester` / `provider`;
- `partnership`: `partner` / `partner`;
- `visibility_share`: `grantor` / `grantee`;
- `affiliation`: `parent` / `child`;
- `participation`: `participant` / `host`;
- `home_base`: `team` / `venue`;
- `service`: `customer` / `provider`.

## Access levels

Access level отвечает на вопрос: какой уровень доступа или участия получает конкретная сторона в рамках конкретного контракта.

Access levels не должны быть одним глобальным enum на всю систему. Они зависят от `scope_type` или `relation_type`.

Примеры:

- `venue` membership: `owner`, `admin`, `manager`, `staff`, `agent`;
- `team` membership: `owner`, `admin`, `coach`, `captain`, `player`, `candidate`;
- `company` membership: `owner`, `admin`, `hr`, `manager`, `employee`, `contractor`;
- `event` membership: `organizer`, `manager`, `staff`, `participant`, `guest`.

## Templates и фактические permissions

Каждый access level имеет шаблонный набор permissions. Шаблон используется как preset при выдаче контракта.

При создании конкретного контракта система должна сохранять фактический набор permissions, выбранный выдающей стороной.

Причина:

- старший участник может выдать младшему уровень `admin`, но снять часть permissions;
- изменение шаблона в будущем не должно автоматически расширять права уже выданных контрактов;
- аудит должен показывать фактические права, выданные конкретным контрактом.

Формула:

```text
access level template
 -> preselected permissions in UI
 -> issuer removes/adds allowed permissions
 -> saved contract permissions snapshot
 -> effective permissions for access checks
```

В текущей реализации используется snapshot-модель:

- `access_level` хранит название выданного уровня;
- `contract_permissions` хранит фактические permissions;
- effective permissions берутся из сохраненного набора, а не вычисляются каждый раз из текущего шаблона.

## Venue transition

Сейчас `venues.created_by_actor_id` является не только audit field, но и bootstrap-источником доступа в `VenueAccessResolver`.

Целевое состояние:

- `created_by_actor_id` остается полем происхождения записи;
- владение площадкой задается `membership_contract` со `scope_type = venue` и `access_level = owner`;
- проверка доступа идет через effective permissions;
- если у площадки еще нет действующего owner membership contract, user, связанный с actor-создателем, получает полный управленческий доступ как bootstrap-owner;
- после появления действующего owner membership contract права управления определяются контрактами, а не фактом создания записи;
- creator fallback удаляется после backfill и проверки owner contracts либо остается только как явно ограниченное bootstrap-правило для сущностей без владельца.

Подтверждённое коммерческое владение начинается только с модерируемой
`VenueOwnershipClaim`, а не с `created_by_actor_id`. Подтверждённый пользователь
передаёт текстовое основание и проверяемые контакты; одновременно у него может
быть только одна pending-заявка на площадку. Решение принимает только
подтверждённый `superadmin`, причём self-approve запрещён.

Одобрение выполняется под mutex строки `venues`: после повторной проверки
отсутствия активного owner создаются `Contract`, `ContractMembership(owner)` и
snapshot всех owner permissions, затем claim переводится в `approved` в той же
транзакции. Повторное одобрение уже approved-заявки идемпотентно. Второй owner
не выдаётся; передача владения остаётся отдельным будущим процессом. Текст
доказательств доступен только заявителю и `superadmin` и исключён из audit log,
а статус, reviewer и причина решения аудируются.

Переходный порядок:

1. Добавить новую модель membership contracts. Выполнено.
2. Создать owner membership contracts для существующих площадок по `created_by_actor_id -> actors.user_id`, если у actor есть user. Это должен делать отдельный backfill-процесс или специализированный data migration, потому что базовый `DatabaseSeeder` остается production-safe и не создает demo venues или contracts.
3. Сохранить creator fallback только для площадок без действующего owner membership contract. Выполнено.
4. Перевести списки и карточки площадок на contract effective permissions. Выполнено для `venue` membership.
5. Удалить creator fallback отдельным шагом, когда данные и тесты подтверждают корректность. Не выполнено, оставлено как будущий этап.

## Проверка доступа

Целевая проверка доступа к площадке:

```text
User wants action on Venue
 -> public visibility for public view
 -> find active venue membership contract
 -> check starts_at/expires_at
 -> read saved contract permissions
 -> check required permission
 -> bootstrap creator fallback only if the Venue has no active owner membership contract
```

Выдача, изменение и отзыв коммерческих ролей сериализуются блокировкой площадки.
Активная роль той же тройки `venue + user + access_level` проверяется внутри этой
транзакции. Отзыв мягкий: `contracts.status=inactive` и `expires_at=now`, история
membership и permissions не удаляется. Отдельная таблица venue memberships и
дублирующий unique index не вводятся, поскольку `ContractMembership` уже
является каноническим хранилищем, а повторная выдача после soft revoke должна
создавать новый исторический contract. Последнего `OWNER` нельзя отозвать или
понизить через общий membership flow; передача владения требует отдельной
атомарной команды.

## Связанная задача

- [009 - Рефакторинг контрактов: membership/relation contracts и контекстные права доступа](../tasks/009/description.md)
