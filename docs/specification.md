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
- [Площадки](#площадки)
- [Контракты](#контракты)
- [Темы и представления](#темы-и-представления)
- [Breadcrumbs](#breadcrumbs)
- [Docker и окружения](#docker-и-окружения)
- [Процесс работы с задачами](#процесс-работы-с-задачами)
- [Git workflow](#git-workflow)
- [Проверки качества](#проверки-качества)
- [Связанные документы](#связанные-документы)

## Назначение документа

`docs/specification.md` хранит общую техническую рамку проекта. Документ нужен, чтобы новые разработчики и AI-агенты понимали не только код, но и правила работы с архитектурой, задачами, ветками, документацией и проверками.

## Техническая картина проекта

- Backend: Laravel, PHP 8.3+.
- Frontend/assets: Vite, npm.
- Docker окружение: текущий `docker-compose.yml` содержит `postgres` и `adminer`.
- Доменные части приложения находятся в `app/Modules`.
- Текущие доменные модули: `Identity`, `Venue`, `Contract`.
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

## Площадки

Предметная область площадок находится в `App\Modules\Venue`. Базовая доменная модель `Venue` хранится в таблице `venues`.

Текущие поля площадки:

- `created_by_user_id`;
- `name`;
- `alias`;
- `type`;
- `status`;
- `description`;
- timestamps.

Текущие классификаторы площадок:

- `VenueStatusEnum` - жизненный статус записи: `unconfirmed`, `confirmed`, `blocked`, `removed`;
- `VenueTypeEnum` - тип площадки.

`Venue` имеет relation `creator()` к `User` по `created_by_user_id`.

Публично видимы площадки со статусом `confirmed`. Дополнительно пользователь может видеть площадки через договорный доступ.

`VenueFeatureEnum`, JSON-поле `features`, нормализованные адреса, `Address` и `Metro` в текущей кодовой базе отсутствуют.

## Контракты

Предметная область контрактов находится в `App\Modules\Contract`.

Текущие модели:

- `Contract`;
- `ContractParty`.

Связанные модели площадок:

- `VenueContract`;
- `VenueContractPermission`.

Текущие таблицы:

- `contracts`;
- `contract_parties`;
- `venue_contracts`;
- `venue_contract_permissions`.

В текущей реализации контракт используется как механизм доступа пользователя к площадкам. Права площадки хранятся в `venue_contract_permissions` как значения:

- `view`;
- `edit`;
- `edit.schedule`.

`contract_parties` уже задает более общую модель участников контракта через `party_type`, `party_id` и `role`, но универсальный ACL между любыми доменными сущностями пока не реализован. Его нужно описывать как направление развития, а не как готовую часть системы.

В таблице `contracts` есть поле `assigned_by`, но в модели `Contract` сейчас нет relation/accessor `assignedByUser`. Этот слой нельзя описывать как готовый без изменения кода.

## Темы и представления

Текущая конфигурация тем находится в `config/themes.php`.

Сейчас есть две директории:

- `resources/themes/mskba_dark` - основная проработанная тема с большинством страниц пользовательской части;
- `resources/themes/blank` - минимальная тема-заготовка.

Активная тема по умолчанию: `mskba_dark`.

`ThemeResolver` выбирает активную тему из `APP_THEME`, регистрирует namespace `theme` и подключает Vite inputs активной темы.

Метод `ThemeResolver::page()` использует fallback `theme::pages.system.view_not_found`, если запрошенной страницы нет. Такой fallback должен существовать в активной теме, иначе отсутствующая страница приведет к ошибке рендера вместо аккуратного view.

Маршрут `/dashboard` сейчас есть, но view `pages/dashboard.blade.php` отсутствует в обеих текущих темах.

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

В текущем compose есть только `postgres` и `adminer`. Сервисы `phpfpm`, `nginx`, `redis`, `mailpit`, `node`, dev override и production compose-сценарий не реализованы.

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
- [Правила ведения документации](specification/documentation-guidelines.md)
- [Процесс работы с задачами](specification/task-workflow.md)
