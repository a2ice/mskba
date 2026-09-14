# 178 — Перевести каталог спортивных секций на `default_category`

## Цель

После стабилизации доменной модели SportsSection в Tasks 175–177 перевести публичный `/sections` на общий presentation-pattern `default_category` из Task 171 без отдельного каталожного shell.

## Реализовано

Публичный каталог секций переведён на `theme::layouts.default-category` и теперь использует тот же UX-контракт, что площадки, мероприятия, турниры и команды:

- полный `inner`, breadcrumbs, компактный заголовок;
- desktop `sidebar + content`;
- accordion `Навигация / Фильтры` в sidebar;
- sticky sidebar и sticky toolbar;
- на `<= 768px` навигация уходит в общий mobile section navigation, а фильтры открываются в modal;
- поиск находится в toolbar с фактическим placeholder `Название или описание`;
- режимы `cards|list|map`, default `cards`;
- search/forms/pagination сохраняют query state и `view`.

## Фильтры

Каталог использует итоговую модель после Tasks 175–177:

- формат тренировок: `Групповой|Индивидуально`;
- направление: `Баскетбол|Стритбол|Другое`;
- стоимость: `Бесплатно|Платно`;
- primary площадка;
- связанная постоянная активная команда через M:N relation Task 177;
- `Принимает заявки`;
- `Активно ведёт набор`.

Boolean-фильтры набора используют общий `form-toggle`. Legacy `small_group|team` и игровые `5×5|3×3|1×1` в SportsSection catalog не возвращены.

## Навигация

Sidebar содержит:

- `Все секции`;
- `Принимают заявки`;
- `Идёт набор`;
- для авторизованного пользователя — `Мои секции` и `Создать секцию`;
- для гостя `Создать секцию` открывает общий `auth-entry-classic` и передаёт redirect в существующий `/account/sections/create` flow.

Toolbar также содержит compact create action с тем же auth/redirect поведением.

## Cards и List

Добавлен entity-specific presenter `SportsSectionCatalogPresenter`. Он формирует presentation data без переноса SportsSection-логики в shared `default_category`.

Карточка и строка показывают:

- featured image с fallback;
- название и описание;
- training mode и направление;
- primary площадку;
- только публично пригодные связанные Team: постоянные и `active`;
- количество активных занимающихся;
- понятную стоимость (`Бесплатно`, `Платно` или фактическая цена за занятие);
- badges `Принимает заявки` и усиленный `Идёт набор`;
- переход на публичную страницу секции.

List на mobile сохраняет ключевые metadata и статусы, а не сворачивается до картинки и названия.

## Map

Добавлен lazy map renderer `sports-section-catalog.js`.

Инварианты:

- источник географии — только `SportsSection.primaryVenue`;
- координаты берутся из `primaryVenue.location.address`;
- отсутствие primary Venue или координат не скрывает секцию из cards/list;
- несколько секций на одной площадке группируются в одной координате и доступны из balloon;
- несколько различных точек fit-ятся по фактическому viewport с ограничением auto zoom;
- карта использует общий `loadYandexMaps`, поэтому наследует cooperative interactions: обычный page scroll не перехватывается случайным zoom/drag и hidden→visible viewport синхронизируется общим wrapper;
- переключение `cards/list → map` использует общий Task 171 view-switcher и viewport behavior.

## Backend и производительность

`SportsSectionController@index` теперь заранее загружает:

- featured media;
- primary Venue + Address;
- связанные активные постоянные Team;
- count активных trainee memberships.

Team-filter реализован через итоговую M:N relation Task 177. Dropdown команд содержит только активные постоянные Team, реально связанные хотя бы с одной активной SportsSection. Presentation не создаёт N+1.

## Проверки

Добавлен `SportsSectionCatalogDefaultCategoryTest`, который проверяет:

- hooks общего `default_category`;
- `view=map/list` state;
- карту только по primary Venue coordinates;
- присутствие секций без координат в обычных представлениях;
- recruitment badges;
- отображение связанной активной Team и исключение архивной;
- normalized section filters и team relation filter;
- отсутствие legacy labels;
- guest create → общий auth-entry redirect.

Первый полный CI после реализации: PHP test suite — success, frontend production build — success.

## Основные файлы

- `app/Modules/SportsSection/Presentation/Catalog/SportsSectionCatalogPresenter.php`;
- `app/Modules/SportsSection/Presentation/Http/Controllers/SportsSectionController.php`;
- `resources/themes/mskba_dark/views/pages/sports-sections/index.blade.php`;
- `resources/themes/mskba_dark/views/pages/sports-sections/partials/catalog-*.blade.php`;
- `resources/themes/mskba_dark/css/pages/sports-section-catalog.css`;
- `resources/themes/mskba_dark/js/features/sports-section-catalog.js`;
- `tests/Feature/SportsSection/SportsSectionCatalogDefaultCategoryTest.php`.

## Зависимости

Tasks 171, 175, 176, 177.

## Ветка

`feature/178`

## Статус

Реализовано в `feature/178`. Основной CI на реализации зелёный; после документационного закрытия требуется финальный зелёный CI, merge в `main` и production deploy.
