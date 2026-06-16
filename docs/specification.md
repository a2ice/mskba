# Specification

Технический документ для разработчиков и AI-агентов. Здесь фиксируются архитектурные правила, процесс разработки, соглашения проекта и ссылки на более подробные технические документы.

## Оглавление

- [Назначение документа](#назначение-документа)
- [Техническая картина проекта](#техническая-картина-проекта)
- [Архитектурные принципы](#архитектурные-принципы)
- [Документация как часть разработки](#документация-как-часть-разработки)
- [Правила работы AI-агента](#правила-работы-ai-агента)
- [Роли в identity-слое](#роли-в-identity-слое)
- [Профиль пользователя](#профиль-пользователя)
- [Подтверждение аккаунта](#подтверждение-аккаунта)
- [Профили участия пользователя](#профили-участия-пользователя)
- [Контакты](#контакты)
- [Локации](#локации)
- [Уведомления](#уведомления)
- [Площадки](#площадки)
- [Админка](#админка)
- [Контракты](#контракты)
- [Целевая модель контрактов](#целевая-модель-контрактов)
- [Темы и представления](#темы-и-представления)
- [Breadcrumbs](#breadcrumbs)
- [Docker и окружения](#docker-и-окружения)
- [Базовый сидер](#базовый-сидер)
- [Процесс работы с задачами](#процесс-работы-с-задачами)
- [Git workflow](#git-workflow)
- [Проверки качества](#проверки-качества)
- [Связанные документы](#связанные-документы)

## Назначение документа

`docs/specification.md` хранит общую техническую рамку проекта. Документ нужен, чтобы новые разработчики и AI-агенты понимали не только код, но и правила работы с архитектурой, задачами, ветками, документацией и проверками.

## Техническая картина проекта

- Backend: Laravel, PHP 8.3+.
- Frontend/assets: Vite, npm.
- Docker окружение: общие сервисы описаны в `compose.yaml`; local/dev настройки находятся в `compose.override.yaml`; VDS/prod настройки находятся в `compose.prod.yaml`.
- Доменные части приложения находятся в `app/Modules`.
- Текущие доменные модули: `Identity`, `Contact`, `Location`, `Notification`, `Venue`, `Contract`.
- Основная тема находится в `resources/themes/mskba_dark`.
- Минимальная тема-заготовка находится в `resources/themes/blank`.
- Внешний backlog быстрых записей ведется во внешнем файле `../backlog/todo.md`.

## Архитектурные принципы

- Сохранять разделение модулей на `Domain`, `Application`, `Infrastructure`, `Presentation`.
- Держать контроллеры тонкими.
- Выносить пользовательские сценарии и бизнес-действия в use case или application-сервисы.
- Держать доменные правила в доменном слое.
- Именовать классы value object с суффиксом `VO`, например `PasswordVO`.
- Не смешивать продуктовую логику с Blade-шаблонами и JS темы.
- Не добавлять абстракции без практической необходимости.

Если общий модуль `App\Modules\Shared` понадобится для низкоуровневых value object или классификаторов, его нужно вводить отдельной задачей. В текущей кодовой базе такого модуля нет.

## Документация как часть разработки

Любое важное изменение архитектуры, пользовательского flow, роли, бизнес-процесса, интеграции или правила разработки должно сопровождаться обновлением документации.

Продуктовые и пользовательские изменения описываются в `docs/project.md` и, при необходимости, в `docs/project/{alias}.md`.

Технические решения, паттерны, правила разработки и процессные инструкции описываются в `docs/specification.md` и, при необходимости, в `docs/specification/{alias}.md`.

Подробнее: [Правила ведения документации](specification/documentation-guidelines.md).

## Правила работы AI-агента

Отдельные правила поведения AI-агента, накопленные корректировки по способу работы и повторяемые case-rules ведутся в [Agent Rules](specification/agent-rules.md).

## Роли в identity-слое

Техническая модель системных ролей и ролей участия пользователя описана в [Identity Roles](specification/identity-roles.md).

## Профиль пользователя

Техническая модель базового профиля пользователя в `Identity` описана в [Identity User Profile](specification/identity-user-profile.md).

## Подтверждение аккаунта

Техническая модель wizard подтверждения аккаунта описана в [Identity Account Confirmation](specification/identity-account-confirmation.md).

## Профили участия пользователя

Техническая модель предметных профилей участия пользователя в `Identity` описана в [Identity Participation Profiles](specification/identity-participation-profiles.md).

## Контакты

Техническая модель контактных каналов и подтверждений описана в [Contact](specification/contact.md).

Контакты вынесены в отдельный доменный модуль `App\Modules\Contact`, потому что один и тот же механизм нужен пользователям, площадкам и будущим сущностям проекта.

## Локации

Техническая модель физических локаций, адресов и метро описана в [Location](specification/location.md).

Локации вынесены в отдельный доменный модуль `App\Modules\Location`, потому что адреса и метро нужны площадкам, будущим событиям, тренировкам, командам и другим сущностям с физическим местом.

## Уведомления

Техническая модель пользовательских in-app уведомлений описана в [Notification](specification/notification.md).

Уведомления вынесены в отдельный доменный модуль `App\Modules\Notification`, потому что они создаются событиями разных предметных областей. Сообщения/переписка не входят в этот модуль и должны проектироваться как отдельный bounded context.

## Площадки

Предметная область площадок находится в `App\Modules\Venue`. Базовая доменная модель `Venue` хранится в таблице `venues`.

Текущие поля площадки:

- `created_by_actor_id`;
- `location_id`;
- `name`;
- `alias`;
- `type`;
- `status`;
- `description`;
- `raw_address`;
- timestamps.

Текущие классификаторы площадок:

- `VenueStatusEnum` - жизненный статус записи: `unconfirmed`, `confirmed`, `blocked`, `removed`;
- `VenueTypeEnum` - тип площадки.

`Venue` имеет relation `creatorActor()` к `Actor` по `created_by_actor_id` и relation `location()` к `Location` по `location_id`.

Публично видимы площадки со статусом `confirmed`. Дополнительно пользователь может видеть площадки через договорный доступ.

Если структурированная локация еще не создана, площадка может хранить fallback-адрес в `raw_address`.

Форма создания площадки использует backend endpoint `/integrations/address-suggest` для Yandex-подсказок адреса. Endpoint нормализует ответ внешнего API, подбирает локальные станции метро и не раскрывает `YANDEX_MAPS_API_KEY` на клиенте. Без настроенного ключа форма продолжает работать через ручной ввод адреса и fallback `raw_address`.

Создание площадки разрешено подтвержденному пользователю через Gate `add_venue`. Каноническая точка входа находится на `/venues/create`, отправка формы идет через `POST /venues`. Личный кабинет не имеет отдельного create/store маршрута площадок и ведет пользователя на этот же публичный URL. GET-страница `/venues/create` доступна гостям и неподтвержденным пользователям, но вместо формы показывает контекстное действие: открыть auth-modal или перейти на подтверждение аккаунта. POST-маршрут защищается `CreateVenueRequest::authorize()`: если право потеряно между открытием формы и отправкой, пользователь возвращается на `/venues/create` с понятным flash-сообщением, а use case создания не вызывается.

`VenueFeatureEnum` и JSON-поле `features` в текущей кодовой базе отсутствуют.

## Админка

Админская часть находится в `App\Modules\Admin`.

Текущая структура:

- `Application/UseCases` - read models для dashboard, users, venues, placeholder events/teams, content и settings;
- `Presentation/Http/Controllers` - тонкие controllers, которые вызывают use case и возвращают themed pages.

Routes находятся под prefix `/admin` и middleware `auth`, `can:access-admin-panel`.

Текущие route names:

- `admin.dashboard` - `/admin`;
- `admin.dashboard.legacy` - `/admin/dashboard`, redirect на `/admin`;
- `admin.users`;
- `admin.venues`;
- `admin.events`;
- `admin.teams`;
- `admin.content`;
- `admin.audit`;
- `admin.settings`.

Gate `access-admin-panel` определен в `App\Providers\AccessServiceProvider` и использует `User::isAdmin()`. Это означает системную роль `admin` или выше и подтвержденный аккаунт.

Dashboard использует отдельный layout `theme::layouts.admin-dashboard`. Внутренние admin-разделы используют общий `theme::layouts.section-sidebar` с admin sidebar partial, чтобы сохранить единую двухколоночную композицию проекта.

Текущая первая итерация админки является read-only каркасом. CRUD, сохранение настроек, content persistence, moderation workflow и прикладные actions должны добавляться отдельными задачами. Audit log уже доступен как read-only раздел `/admin/audit`.

## Аудит

Техническая модель audit logging описана в [Audit logging](architecture/audit.md).

Audit фиксирует изменения доменных сущностей в `audit_logs` и связывает запись с `Actor`, если действие выполнялось в HTTP-flow. Это отдельный системный журнал изменений данных, а не runtime-логи приложения.

## Контракты

Предметная область контрактов находится в `App\Modules\Contract`.

Текущие модели:

- `Contract`;
- `ContractMembership`;
- `ContractRelation`;
- `ContractPermission`.

Текущие таблицы:

- `contracts`;
- `contract_memberships`;
- `contract_relations`;
- `contract_permissions`.

В текущей реализации первым production-scope является `membership_contract` для связи `venue -> user`. Фактические права контракта хранятся в `contract_permissions` как snapshot выданных permissions:

- `view`;
- `edit`;
- `edit.schedule`.

`contract_relations` закладывает схему для будущих связей сущность-сущность, но relation-contract ACL для событий, команд, компаний и других доменов пока не реализован. Его нужно описывать как направление развития, а не как готовую часть системы.

В таблице `contracts` есть поле `assigned_by`, а модель `Contract` содержит relation `assignedByUser()`.

## Целевая модель контрактов

Целевая техническая модель контрактов описана в [Contracts](specification/contracts.md).

Контракты разделяются на два семейства:

- `membership_contract` - связь пользователя с предметной сущностью (`venue -> user`, `team -> user`, `event -> user`, `company -> user`);
- `relation_contract` - связь сущности с сущностью (`event -> venue`, `team -> venue`, `team -> team` и похожие).

Старая модель `holder/provider/customer` удалена из текущей схемы и не является универсальной ACL-моделью. Такие значения допустимы только как контекстные роли отдельных relation types, например сервисного договора. Для остальных связей роли сторон и access levels должны определяться policy конкретного `scope_type` или `relation_type`.

Для `venue` membership стартовые access levels:

- `owner`;
- `admin`;
- `manager`;
- `staff`;
- `agent`.

Шаблон access level используется как preset при выдаче контракта, но в конкретном контракте должен сохраняться фактический snapshot permissions. Это позволяет старшей роли снять часть прав при выдаче `admin` или другого уровня и не расширять старые контракты автоматически при изменении шаблона.

`venues.created_by_actor_id` в целевой модели является audit/source field, а не источником владения. Право владельца площадки должно задаваться `membership_contract` со `scope_type = venue` и `access_level = owner`. Пока у площадки нет действующего owner membership contract, actor-создатель или связанный с ним user/fingerprint может получать полный управленческий доступ как bootstrap-owner. После появления owner membership contract управление должно определяться контрактами, а creator fallback больше не должен давать полную власть над этой площадкой.

## Темы и представления

Текущая конфигурация тем находится в `config/themes.php`.

Сейчас есть две директории:

- `resources/themes/mskba_dark` - основная проработанная тема с большинством страниц пользовательской части;
- `resources/themes/blank` - минимальная тема-заготовка.

Активная тема по умолчанию: `mskba_dark`.

`ThemeResolver` выбирает активную тему из `APP_THEME`, регистрирует namespace `theme` и подключает Vite inputs активной темы.

Метод `ThemeResolver::page()` использует fallback `theme::pages.system.view_not_found`, если запрошенной страницы нет. Такой fallback должен существовать в активной теме, иначе отсутствующая страница приведет к ошибке рендера вместо аккуратного view.

Маршрут `/dashboard` сейчас есть, но view `pages/dashboard.blade.php` отсутствует в обеих текущих темах.

В теме `mskba_dark` элементы пользовательского интерфейса с атрибутом `title` автоматически получают кастомный tooltip. JS enhancer переносит текст из `title` в кастомный tooltip и удаляет нативный `title`, чтобы не было двойной подсказки браузера. По умолчанию используется вариант `question` с отдельной кнопкой `?`; для уже существующих визуальных маркеров можно указать `data-tooltip-variant="title"`, чтобы tooltip был привязан к самому элементу без дополнительной иконки.

Для разделов с левой колонкой используется общий grid-based layout `theme::layouts.section-sidebar`. Он задает композицию: breadcrumbs, sidebar слева и основной content panel справа. Sidebar заполняется секцией `section-sidebar`, основной контент - секцией `section-content`. Если в sidebar нужен навигационный список, нужно использовать общий partial `theme::partials.menu.sidebar` и добавить соответствующий handler в `config/menus.php`, как это сделано для `venues`. Заголовки content panel используют общий класс `layout-content-title`. Layout предназначен для разделов вроде площадок, игр, тренировок, событий, команд и внутренних разделов с sidebar.

Account-страницы напрямую используют `theme::layouts.section-sidebar` и передают `theme::partials.account.sidebar` как `sidebarPartial`. Отдельный `theme::layouts.account` больше не используется, чтобы account и публичные предметные разделы имели одну layout-сетку и одинаковую ширину sidebar.

## Breadcrumbs

Partial `theme::partials.breadcrumbs` строит цепочку навигации через `App\Presentation\Breadcrumbs\BreadcrumbsResolver`.

Resolver может использовать:

- имя текущего маршрута;
- `breadcrumb` defaults маршрута;
- заголовок страницы, если он передан в partial.

В текущих маршрутах `breadcrumb` default явно задан только для маршрута `venues`.

Соглашение вида `section.index` полезно для новых разделов, но текущие route names не везде ему следуют (`venues`, `account`). Поэтому документация не должна утверждать, что все текущие breadcrumbs уже построены по этому соглашению.

## Docker и окружения

Текущая Docker-схема описана в [Docker Environment](specification/docker-environment.md).

Локальный compose остается DB-only: `postgres` и `adminer`.

Production compose добавлен отдельно и содержит `phpfpm`, `nginx`, `db`, `redis` и build-only сервис `node`. Старую версию проекта можно полностью удалять вместе с БД, контейнерами, volume и другими артефактами, если они мешают новой production-схеме.

## Базовый сидер

`DatabaseSeeder` является production-safe bootstrap сидером. Он не создает demo users, demo venues, contracts, fake profiles или случайные данные.

Текущий сидер создает и обновляет только:

- identity bootstrap пользователя `superadmin` со статусом `confirmed`, системной ролью `superadmin` и базовым профилем;
- справочник московского метро: `metro_lines` и `metro_stations`.

Пароль `superadmin` задается только при первом создании пользователя. Повторный запуск сидера обновляет роль, статус и профиль, но не сбрасывает пароль существующего пользователя.

Справочник метро берется из локального файла `database/seeders/data/moscow_metro.json`. Файл подготовлен из публичного набора `nalgeon/metro`, который указывает HeadHunter API как upstream источник. Сидер не выполняет сетевые запросы во время запуска.

## Процесс работы с задачами

Разработка ведется через задачи из `docs/tasks.md`. Для каждой задачи создается папка `docs/tasks/{NNN}` с описанием, планом, статусом и итогами. Дополнительный внешний backlog быстрых записей и важных возникающих задач ведется во внешнем файле `../backlog/todo.md`.

Подробнее: [Процесс работы с задачами](specification/task-workflow.md).

## Git workflow

- Старт задачи выполняется только из чистой ветки `main`.
- Для задачи создается отдельная ветка в стиле conventional commit: `type/NNN`.
- Примеры: `feature/123`, `fix/124`, `docs/125`, `refactor/126`.
- После выполнения и проверки задача коммитится в своей ветке.
- Затем запрашивается подтверждение на merge в `main`.
- После merge задача отмечается выполненной в `docs/tasks.md` уже из `main`.

## Проверки качества

Минимальные проверки выбираются по затронутой области:

- PHP/backend: `composer test` или `make test`.
- Frontend/assets: `npm run build` или `make build`.
- Стиль PHP: `make lint` или `make format`.
- Маршруты: `php artisan route:list`.
- Кеши Laravel: `php artisan optimize:clear` или `make optimize-clear`.
- Документация: `git diff --check`.

Если проверку невозможно выполнить, это нужно явно зафиксировать в описании задачи и в итоговом сообщении.

## Связанные документы

- [Продуктовая документация](project.md)
- [Agent Rules](specification/agent-rules.md)
- [Docker Environment](specification/docker-environment.md)
- [Identity Roles](specification/identity-roles.md)
- [Identity User Profile](specification/identity-user-profile.md)
- [Identity Account Confirmation](specification/identity-account-confirmation.md)
- [Identity Participation Profiles](specification/identity-participation-profiles.md)
- [Contact](specification/contact.md)
- [Location](specification/location.md)
- [Notification](specification/notification.md)
- [Contracts](specification/contracts.md)
- [Audit logging](architecture/audit.md)
- [Правила ведения документации](specification/documentation-guidelines.md)
- [Процесс работы с задачами](specification/task-workflow.md)
