# `default_category` — стандарт публичных каталогов

`default_category` — presentation-pattern для однотипных публичных страниц-каталогов MSKBA: площадок, мероприятий, турниров, команд, секций и будущих сущностей с похожей моделью поиска/фильтрации.

Паттерн унифицирует только оболочку страницы. Контроллеры, доменные фильтры, карточки, карта и бизнес-правила остаются у конкретного bounded context.

## Базовый layout

Основной Blade layout: `theme::layouts.default-category`.

Композиция desktop:

1. заголовок категории + `Назад`;
2. общий `inner` без дополнительного локального ограничения ширины;
3. двухколоночный grid `sidebar + main`;
4. sticky sidebar под header;
5. sticky toolbar над результатами;
6. область результатов с режимами `cards`, `list` и опционально `map`.

На ширине `<= 768px` навигационная часть sidebar переезжает в существующую mobile section navigation, desktop-фильтры скрываются, а в toolbar появляется icon-action `Фильтры`, открывающий modal с теми же условиями.

## Контракт layout

При подключении layout consumer передаёт:

- `title` — заголовок категории;
- `categoryId` — стабильный id/root-hook;
- `categoryClass` — page-specific class;
- `currentView` — `cards|list|map`, по умолчанию `cards`;
- `hasMap` — поддерживает ли каталог карту;
- `mobileFilterModalId` — id popup фильтров.

Используемые sections/slots:

- `category-navigation` — навигация раздела, без фильтров;
- `category-filters-desktop` — доменные фильтры desktop;
- `category-search` — search control/form;
- `category-active-filter-count` — счётчик активных фильтров для mobile action;
- `category-toolbar-actions` — `Создать`, `Добавить` и другие контекстные действия;
- `category-results-cards` — карточочный режим;
- `category-results-list` — списочный режим;
- `category-results-map` — map mode, только при `hasMap=true`;
- `category-pagination` — пагинация, если consumer её использует;
- `category-mobile-filters` — modal с мобильной версией фильтров.

Неиспользуемые capabilities не должны имитироваться пустой доменной логикой. Например, каталог без географического смысла передаёт `hasMap=false` и не рендерит map slot/action.

## View switcher

Общий переключатель реализован в `resources/themes/mskba_dark/js/features/default-category.js`.

Правила:

- `cards` — default и может быть представлен отсутствием query-параметра `view`;
- `list` — query `view=list`;
- `map` — query `view=map`, только для map-capable consumer;
- основная кнопка списка icon-only и раскрывает dropdown `Карточками / Списком`;
- карта — отдельная icon-only action;
- icon-actions используют `aria-label`, `title` и обычный tooltip без helper-question-mark и underline;
- при переключении view состояние синхронизируется с hidden inputs desktop/mobile filter forms и URL через `history.replaceState`;
- consumer может слушать custom event `default-category:viewchange` и лениво инициализировать тяжёлую доменную функциональность, например карту.

## Search и фильтры

Search остаётся в toolbar и задаётся consumer-ом, включая placeholder.

Desktop (`>768px`):

- доменные фильтры находятся в sidebar;
- sidebar и toolbar sticky;
- фильтры не дублируются отдельной раскрывающейся полосой под toolbar.

Mobile (`<=768px`):

- toolbar содержит icon-action `Фильтры`;
- фильтры открываются в modal;
- mobile form использует те же query-параметры, что desktop;
- `Применить` и `Сбросить` обязаны сохранять search и текущий view;
- boolean controls должны использовать фирменный `form-toggle`, а не произвольные browser-checkbox.

## Разделение shared и domain-specific кода

Shared слой отвечает за:

- shell/grid/responsive;
- sticky sidebar/toolbar;
- view switcher;
- mobile filter entrypoint;
- синхронизацию view state.

Consumer отвечает за:

- набор фильтров;
- query/validation;
- card/list partials;
- map data и map renderer;
- permissions/create flow;
- empty states и специфичные badges/meta.

Запрещено превращать `default_category` в универсальный контроллер или компонент со знаниями о Venue, Event, Team, Tournament и SportsSection одновременно.

## Assets

Общий JS подключается через существующий frontend entrypoint и инициализируется только при наличии `[data-default-category]`.

Тяжёлые entity-specific возможности должны иметь собственный root/data-hook и запускаться только на соответствующей странице. На первом consumer `/venues` `venue-catalog.js` оставляет только map-specific поведение; общий search/view UX вынесен из него.

## Первый consumer: `/venues`

Task 171 переводит каталог площадок на этот паттерн.

Venue-specific partials находятся в `views/pages/venues/partials/`:

- `catalog-navigation.blade.php`;
- `catalog-filters.blade.php`;
- `catalog-item.blade.php`.

Особенности Venue не должны переноситься в shared layout. Карта продолжает использовать данные площадок и Yandex Maps через `venue-catalog.js`.

## Правило для новых каталогов

Новая страница вида «категория/каталог» сначала рассматривается как consumer `default_category`. Отдельный собственный shell допускается только если страница принципиально не укладывается в модель `sidebar + toolbar + results`, а не из-за локального удобства разработки.
