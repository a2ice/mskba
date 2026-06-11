# 018 - Спроектировать и реализовать базовую админку

## Оригинальное описание

Пользователь попросил сделать базовую админку `/admin`:

- dashboard с иконками: "Пользователи", "Площадки", "События", "Команды", "Контент", "Настройки";
- базовый шаблон для разделов, кроме настроек и контента, с пагинацией и фильтрами;
- для настроек - шаблон с базовым набором настроек;
- для контента - список страниц с SEO title, keywords, description;
- реализация должна быть качественной, в концепции проекта, с тонкими контроллерами, проверкой безопасности и декомпозицией.

## Текущее состояние

В коде уже есть route:

- `GET /admin/dashboard`;
- middleware: `auth`, `can:access-admin-panel`;
- route name: `admin.dashboard`.

Gate `access-admin-panel` определен в `App\Providers\AccessServiceProvider` через `User::isAdmin()`. По текущей реализации это означает системную роль `admin` или выше и подтвержденный аккаунт.

Полноценной view `theme::pages.admin.dashboard`, admin layout, admin controllers/use cases и внутренних admin-разделов пока нет.

## Цель первой итерации

Сделать рабочий каркас админки без полноценного CRUD:

- админский dashboard `/admin`;
- route alias/redirect `/admin/dashboard`;
- разделы:
  - `/admin/users`;
  - `/admin/venues`;
  - `/admin/events`;
  - `/admin/teams`;
  - `/admin/content`;
  - `/admin/settings`;
- отдельный dashboard layout для `/admin`;
- внутренние разделы админки на базе существующего `theme::layouts.section-sidebar`;
- единая sidebar/top navigation админки;
- list-шаблон для users/venues/events/teams с фильтрами и пагинацией;
- content-шаблон со списком страниц и SEO-полями;
- settings-шаблон с базовыми настройками;
- тонкие controllers, отдельные application use cases/read models для данных списков;
- security coverage для доступа.

## Границы первой итерации

В задачу не входит:

- полноценный CRUD пользователей, площадок, событий, команд;
- сохранение настроек в БД;
- сохранение content/SEO-страниц в БД;
- создание доменных контекстов Events/Teams/Content/Settings в полном виде;
- массовые actions, audit log, impersonation, moderation workflow.

Для еще не реализованных доменов Events/Teams/Content/Settings допускаются read-only placeholder data providers и каркасные страницы, но структура должна быть готова к замене на реальные use cases.

## Архитектурное решение

Админка оформляется отдельным presentation/application срезом, не смешивающимся с публичными страницами:

- `App\Modules\Admin\Presentation\Http\Controllers`;
- `App\Modules\Admin\Application\UseCases`;
- `resources/themes/mskba_dark/views/layouts/admin-dashboard.blade.php`;
- `resources/themes/mskba_dark/views/pages/admin/*`;
- `resources/themes/mskba_dark/views/partials/admin/*`;
- `resources/themes/mskba_dark/css/pages/admin.css`.

Dashboard использует собственный layout, потому что это стартовая панель с плитками разделов, а не двухколоночный список.

Внутренние разделы админки используют существующий `theme::layouts.section-sidebar`. Sidebar заполняется admin partial/menu, а основной контент - таблицами, фильтрами, pagination или специализированными шаблонами content/settings. Это сохраняет единую ширину sidebar, breadcrumbs и общую композицию с уже сделанными разделами проекта.

Контроллеры должны:

- принимать request;
- вызывать use case/read model;
- отдавать `ThemeResolver::page(...)`;
- не содержать query-building и бизнес-логику.

Use cases/read models должны:

- готовить данные для таблиц;
- принимать фильтры и page size;
- возвращать paginator или DTO;
- для будущих разделов возвращать стабильную структуру placeholder-данных.

## UI/UX концепция

Админка - рабочий operational-интерфейс, не landing page:

- плотная, сканируемая сетка;
- без hero-блоков;
- без декоративных карточек внутри карточек;
- dashboard с компактными action tiles с иконками;
- внутренние разделы используют двухколоночную структуру `section-sidebar`;
- таблицы/списки с фильтрами сверху;
- понятные empty states;
- единый sidebar с активным разделом;
- mobile layout должен складываться без наложений.

Иконки: использовать Tabler icons, потому что webfont уже подключен в теме.

## Страницы

### Dashboard

`/admin`

Содержит плитки:

- Пользователи;
- Площадки;
- События;
- Команды;
- Контент;
- Настройки.

Каждая плитка ведет в соответствующий раздел и показывает короткий счетчик/статус, где данные уже доступны.

### Users

`/admin/users`

Базовый list-view:

- фильтры: поиск по username, status, system role;
- таблица: ID, логин, статус, системная роль, дата регистрации;
- пагинация.

Источник данных: `Identity` users через admin use case.

### Venues

`/admin/venues`

Базовый list-view:

- фильтры: поиск, status, type;
- таблица: ID, название, alias, статус, тип, создатель, дата создания;
- пагинация.

Источник данных: `Venue`.

### Events

`/admin/events`

Каркас list-view с фильтрами и empty state, потому что доменный контекст событий еще не реализован.

Фильтры-заготовки:

- поиск;
- статус;
- период.

### Teams

`/admin/teams`

Каркас list-view с фильтрами и empty state, потому что доменный контекст команд еще не реализован.

Фильтры-заготовки:

- поиск;
- статус;
- тип.

### Content

`/admin/content`

Список страниц с SEO-полями:

- slug/path;
- title;
- SEO title;
- keywords;
- description;
- status/updated_at как placeholder.

В первой итерации данные могут быть статическим read model списком основных страниц: главная, площадки, FAQ, первые шаги.

### Settings

`/admin/settings`

Шаблон базовых настроек:

- проект: название, публичный режим;
- регистрация: включена/выключена как placeholder;
- модерация площадок: требуется подтверждение как placeholder;
- SEO defaults: default title, keywords, description как placeholder;
- системное обслуживание: версия/окружение/read-only status.

В первой итерации форма может быть read-only или disabled, если сохранение настроек не реализуется.

## Безопасность

Обязательные правила:

- все admin routes под middleware `auth` и `can:access-admin-panel`;
- guest получает redirect на login;
- обычный confirmed user получает 403;
- unconfirmed admin получает 403 из-за `User::isAdmin()`;
- confirmed admin/superadmin получает доступ;
- ссылки в main menu показываются только пользователям с admin-доступом;
- формы фильтров используют GET и не меняют состояние.

## Декомпозиция

### Этап 1. Планирование и документация

Результат:

- задача 018 заведена;
- описан scope, security model, страницы, проверки;
- обновлены `docs/project.md` и `docs/specification.md` перед/после реализации.

### Этап 2. Routing и controller skeleton

Результат:

- route group `/admin`;
- `GET /admin` как dashboard;
- redirect или совместимость `/admin/dashboard`;
- routes для users, venues, events, teams, content, settings;
- admin controllers без бизнес-логики.

### Этап 3. Application read models

Результат:

- dashboard summary use case;
- users list use case;
- venues list use case;
- placeholder events/teams list use cases;
- content pages list use case;
- settings view model use case.

### Этап 4. Admin layout и navigation

Результат:

- `theme::layouts.admin-dashboard`;
- внутренние admin pages подключены через `theme::layouts.section-sidebar`;
- admin sidebar/top area;
- общие partials для admin section heading, filters, table, pagination;
- CSS `pages/admin.css` подключен в theme bundle.

### Этап 5. Dashboard UI

Результат:

- плитки разделов с Tabler icons;
- счетчики users/venues и статусы заглушек;
- адаптивная сетка.

### Этап 6. Generic list template

Результат:

- единый reusable Blade partial для list pages;
- users/venues используют реальные данные;
- events/teams используют empty state и фильтры-заготовки;
- пагинация Laravel paginator.

### Этап 7. Content и Settings templates

Результат:

- content list с SEO columns;
- settings sections с read-only/disabled controls;
- понятные подписи, что сохранение будет отдельной задачей.

### Этап 8. Проверки и hardening

Результат:

- feature tests доступа;
- feature tests admin dashboard/routes;
- feature tests users/venues filters/pagination smoke;
- `php artisan route:list --path=admin`;
- `php artisan test --filter Admin`;
- `npm run build`;
- `git diff --check`.

## Риски и решения

- Риск: слишком широкий scope. Решение: первая итерация только каркас и read-only списки, без CRUD.
- Риск: смешение admin dashboard и внутренних списочных экранов. Решение: dashboard получает отдельный layout, внутренние разделы используют `section-sidebar` с admin sidebar partial.
- Риск: будущие Events/Teams еще не существуют. Решение: placeholder read models с тем же контрактом, что и будущие реальные списки.
- Риск: настройки и контент выглядят редактируемыми без сохранения. Решение: disabled/read-only controls и явная документация, что persistence будет отдельной задачей.

## Проверки

Планируемые проверки:

- `php artisan route:list --path=admin`;
- `php artisan test --filter Admin`;
- `npm run build`;
- `git diff --check`.

## Результат

Реализация добавляет первую read-only итерацию админки:

- route group `/admin` под `auth` и `can:access-admin-panel`;
- `/admin` как dashboard;
- `/admin/dashboard` как legacy redirect на `/admin`;
- разделы users, venues, events, teams, content, settings;
- `App\Modules\Admin` с тонкими controllers и application use cases/read models;
- dashboard layout `theme::layouts.admin-dashboard`;
- внутренние страницы на `theme::layouts.section-sidebar`;
- admin sidebar menu;
- dashboard tiles с Tabler icons;
- users/venues list views с фильтрами и пагинацией;
- events/teams placeholder list views;
- content read-only SEO list;
- settings read-only template.

Реализованные проверки:

- feature tests доступа к админке;
- feature tests dashboard tiles;
- feature tests users/venues filtering.

Проверки выполнены:

- `find app/Modules/Admin -name '*.php' -print0 | xargs -0 -n1 php -l` - пройдено;
- `php -l routes/web.php` - пройдено;
- `php -l app/Presentation/Navigation/Menus/AdminMenu.php` - пройдено;
- `php artisan route:list --path=admin` - пройдено, 8 GET/HEAD routes;
- `php artisan test --filter Admin` - пройдено, 7 tests / 24 assertions;
- `npm run build` - пройдено;
- `git diff --check` - пройдено.
