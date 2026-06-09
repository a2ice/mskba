# 014 - Добавить общий двухколоночный layout для предметных разделов

## Оригинальное описание

Пользователь попросил:

> разработаем новый шаблон по типу account.blade.php, который будут использовать вероятно и раздел "площадки" и "игры/тренировки/события" и "команды" - в целом данный шаблон будет похож на account.blade.php - слева также будет sidebar(фильтры, подменю и прочее) а справа основной контент.

## Подробное описание

Нужно добавить в тему `mskba_dark` общий layout для предметных публичных разделов.

Требования:

- layout должен быть похож на `layouts/account.blade.php` по общей композиции;
- слева должна быть зона sidebar для фильтров, подменю и других дополнительных блоков;
- справа должна быть основная зона контента;
- layout должен быть достаточно универсальным для будущих разделов: площадки, игры, тренировки, события, команды;
- навигационное меню в sidebar должно использовать общий partial `theme::partials.menu.sidebar`, как в профиле;
- заголовок основной области должен использовать общий стиль layout-заголовков и быть компактнее первоначального варианта;
- account-страницы должны напрямую использовать `section-sidebar`, без промежуточного `account.blade.php`;
- документация должна зафиксировать назначение нового layout и правила его использования.

## Затронутые файлы

- `resources/themes/mskba_dark/views/layouts`;
- `resources/themes/mskba_dark/views/pages/venues`;
- `resources/themes/mskba_dark/css`;
- `docs/specification.md`;
- `docs/tasks.md`.

## Проверки

- `npm run build`;
- при необходимости targeted feature/view тесты для публичных страниц площадок.

## Результат

Добавлен общий layout `theme::layouts.section-sidebar` для публичных предметных разделов с двухколоночной композицией.

Поведение и структура:

- layout наследуется от `theme::layouts.app`;
- сверху выводит breadcrumbs;
- слева выводит sidebar через секцию `section-sidebar`;
- справа выводит основной content panel через секцию `section-content`;
- поддерживает параметры `sectionId`, `sectionClass`, `contentTitle`, `contentSubtitle`, `sidebarLabel`;
- публичные страницы площадок `venues.index` и `venues.show` переведены на новый layout.
- account-страницы напрямую переведены на `theme::layouts.section-sidebar` и больше не используют `theme::layouts.account`.
- account sidebar вынесен в `theme::partials.account.sidebar`, чтобы не дублировать avatar/menu в каждой account-странице.

Навигация площадок добавлена через `App\Presentation\Navigation\Menus\VenuesMenu` и подключена в `config/menus.php`. Sidebar страниц площадок использует `theme::partials.menu.sidebar`, чтобы визуально и технически совпадать с меню профиля.

Заголовок content panel вынесен в общий CSS-класс `layout-content-title`; этот класс используется в `theme::layouts.section-sidebar`.

Добавлены стили `resources/themes/mskba_dark/css/pages/section-sidebar.css` и подключены в `resources/themes/mskba_dark/css/app.css`.

Документация `docs/specification.md` фиксирует назначение layout и отличие от account-specific layout.

Проверки:

- `npm run build` - пройден;
- `php artisan test --filter Venue` - пройден.
