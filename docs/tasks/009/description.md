# 009 - Рефакторинг контрактов: membership/relation contracts и контекстные права доступа

## Оригинальное описание

Рефакторинг контрактов: membership/relation contracts и контекстные права доступа.

## Подробное описание

Нужно спроектировать и реализовать следующий слой контрактной модели: разделить текущую площадочную специализацию контрактов на более универсальную архитектуру связей и прав доступа между сущностями.

До рефакторинга код содержал модуль `Contract`, таблицы `contracts`, `contract_parties`, площадочную связку `venue_contracts`/`venue_contract_permissions` и проверку доступа к площадкам через `VenueAccessResolver` и `VenueContractAccess`.

Недостаток старой модели: `contract_parties.role` использовал значения `holder`, `provider`, `customer`. Эти значения похожи на роли сторон сервисного договора, но плохо подходят для универсальных связей:

- площадка - пользователь;
- событие - пользователь;
- команда - игрок;
- компания - сотрудник;
- команда - команда;
- событие - площадка;
- площадка - команда.

Нужно не строить ACL на `holder/provider/customer` как универсальной модели. Если эти понятия сохраняются, они должны быть допустимы только в контекстах, где действительно есть заказчик и исполнитель.

## Целевая концепция

Контракты делятся на два семейства.

### Membership contracts

`membership_contract` описывает связь пользователя с предметной сущностью, в рамках которой пользователь получает роль, уровень доступа или рабочее участие.

Примеры:

- `venue -> user`;
- `event -> user`;
- `team -> user`;
- `company -> user`;
- будущие `league -> user` или похожие связи.

Нейтральные поля:

- `scope_type`;
- `scope_id`;
- `user_id`;
- `access_level`.

`scope_type` используется вместо `organization_type`, потому что не каждая сущность является организацией: `event` и `venue` скорее предметные scopes, чем организации.

Стартовые уровни для `venue` membership:

- `owner`;
- `admin`;
- `manager`;
- `staff`;
- `agent`.

`player` не входит в стартовый набор `venue` membership. Игрок обычно связан с площадкой через событие, команду, тренировку, бронирование или заявку, а не через управленческий контракт площадки.

### Relation contracts

`relation_contract` описывает связь сущности с сущностью.

Примеры:

- `venue -> venue`;
- `venue -> event`;
- `event -> venue`;
- `event -> event`;
- `event -> team`;
- `venue -> team`;
- `team -> venue`;
- `team -> team`;
- `company -> venue`;
- `company -> event`.

Нейтральные поля:

- `relation_type`;
- `left_type`;
- `left_id`;
- `left_role`;
- `right_type`;
- `right_id`;
- `right_role`.

`relation_type` должен описывать смысл связи, а не только пару типов сущностей.

Возможные стартовые значения для анализа:

- `booking` - бронирование или использование ресурса;
- `rental` - аренда;
- `partnership` - партнерская связь;
- `affiliation` - принадлежность или аффилированность;
- `participation` - участие сущности в событии или процессе;
- `hosting` - принимающая сторона;
- `home_base` - домашняя площадка команды;
- `visibility_share` - предоставление видимости данных;
- `management` - управление одной сущностью другой;
- `representation` - представление интересов;
- `sponsorship` - спонсорская связь;
- `service` - сервисный договор, где могут быть уместны роли `customer/provider`;
- `invitation` - приглашение к связи.

Роли сторон не должны быть глобально `customer/provider`. Для каждого `relation_type` policy задает допустимые роли сторон:

- `booking`: `requester` / `provider`;
- `partnership`: `partner` / `partner`;
- `visibility_share`: `grantor` / `grantee`;
- `affiliation`: `parent` / `child`;
- `participation`: `participant` / `host`;
- `home_base`: `team` / `venue`;
- `service`: `customer` / `provider`.

## Access levels и permissions

Access levels должны быть контекстными. Нельзя делать один глобальный enum со значениями `owner`, `admin`, `player`, `hr`, `customer`, `provider` одновременно.

Каждый `scope_type` или `relation_type` должен иметь свой template/policy:

- допустимые access levels;
- стартовый набор permissions для каждого access level;
- правила ручной настройки permissions;
- правила статусов и сроков действия.

Шаблон access level является источником стартового набора прав для UI и выдачи контракта. При создании конкретного контракта нужно сохранять фактический набор permissions, выбранный выдающей стороной.

Пример для `venue` membership:

- owner: все права площадки;
- admin: управление площадкой, расписанием, участниками и контрактами;
- manager: расписание, бронирования и операционные настройки;
- staff: служебный доступ и операционные действия;
- agent: представление, продвижение и коммуникации.

При выдаче `admin` старшая роль видит предзаполненный набор прав и может снять отдельные permissions. Поэтому `access_level = admin` остается названием выданного уровня, а `contract_permissions` хранит фактические права конкретного контракта.

Предпочтительная модель overrides:

- шаблон используется как preset;
- при выдаче контракта сохраняется snapshot фактических permissions;
- изменение шаблона в будущем не должно автоматически расширять права старых контрактов без отдельной миграции или явного процесса обновления.

## Переходная модель для Venue

Сейчас `venues.created_by_user_id` используется как shortcut для доступа создателя к площадке.

Целевое состояние:

- `created_by_user_id` остается audit/source field: кто создал запись;
- право владельца определяется `membership_contract` со `scope_type = venue`, `scope_id = venue.id`, `user_id`, `access_level = owner`;
- если у площадки еще нет действующего owner membership contract, создатель получает полный управленческий доступ как временный bootstrap-owner;
- после появления действующего owner membership contract creator fallback перестает быть источником полного управления для этой площадки;
- после миграции старых площадок на owner membership contracts creator fallback должен быть удален из бизнес-проверок доступа или оставлен только как явно ограниченное bootstrap-правило для сущностей без владельца.

Переходный период:

- создать owner membership contract для существующих площадок по `created_by_user_id`;
- временно сохранить fallback на `created_by_user_id` только для площадок без действующего owner membership contract;
- явно покрыть тестами момент, когда контрактные права имеют приоритет над creator shortcut.

## Предлагаемые этапы

1. Спроектировать точную схему БД и enum/value objects для contract family, membership contracts, relation contracts, relation types и permissions.
2. Принять решение по судьбе `contract_parties.role`: удалить, переименовать в `party_position`/`side` или оставить только как контекстное строковое поле для relation-specific ролей. Решено удалить старую схему `contract_parties`.
3. Реализовать membership contracts для `venue -> user` как первый production-scope. Выполнено.
4. Добавить policy/template слой для `venue` membership access levels. Выполнено через `VenueMembershipAccessLevelEnum`.
5. Перевести выдачу и проверку venue permissions на фактические `contract_permissions`. Выполнено.
6. Добавить backfill для owner membership contracts из `venues.created_by_user_id`. Для чистой схемы выполнено в seeder; для реальной базы нужен отдельный backfill-процесс, если данные появятся до удаления creator fallback.
7. Обновить `VenueAccessResolver`: сначала public-view, затем контрактные effective permissions, затем creator bootstrap fallback только если у площадки нет действующего owner membership contract. Выполнено.
8. Обновить сидеры и личный кабинет контрактов. Выполнено.
9. Добавить feature/unit тесты на выдачу owner/admin/manager/staff/agent и снятие permissions при выдаче контракта.
10. Обновить документацию `docs/project.md`, `docs/specification.md`, `docs/specification/contracts.md` и README модулей. Выполнено.

## Затронутые области

- `App\Modules\Contract`;
- `App\Modules\Venue`;
- `VenueAccessResolver`;
- `VenueMembershipAccess`;
- миграции контрактов и площадок;
- сидеры;
- личный кабинет контрактов;
- продуктовая документация договорного доступа;
- техническая документация контрактов.

## Проверки

Минимальные проверки после реализации:

- `composer test` или `make test`;
- targeted feature tests для venue access;
- `php artisan route:list`, если меняются маршруты;
- `git diff --check`.

Если будут изменения frontend-экранов выдачи контрактов, дополнительно:

- `npm run build` или `make build`.

Выполненные проверки:

- `php artisan test tests/Feature/Venue/VenueMembershipAccessTest.php` - успешно;
- `php artisan migrate:fresh --seed --env=testing` - успешно;
- `git diff --check` - успешно;
- `php artisan test` - есть 1 падение вне области задачи: `AccountConfirmationWizardTest::test_unconfirmed_user_sees_confirmation_button_on_account_page` ожидает кнопку "Подтвердить аккаунт", а текущий экран предлагает сначала добавить контакт.

## Статус

Реализация выполнена. Требуется финальное ревью и решение по существующему падению теста wizard подтверждения аккаунта вне области контрактного рефакторинга.
