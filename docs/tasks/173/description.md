# 173 — Перевести каталог турниров на `default_category`

## Цель

Привести `/tournaments` к общему стандарту `default_category` после Task 171, сохранив tournament lifecycle, режимы набора, формат и существующие права.

## Реализация

Каталог турниров переведён с legacy `section-sidebar` на общий `theme::layouts.default-category`.

Используются:
- общий accordion-sidebar;
- sticky toolbar;
- поиск по названию в toolbar;
- desktop-фильтры в sidebar и тот же набор фильтров в mobile popup;
- `cards | list | map`, default — `cards`;
- сохранение `view`, period, search, date filters и pagination query state.

Sidebar сохраняет существующую честную навигацию турниров: `Все турниры`, `Текущие`, `Предстоящие`, `Прошедшие`; действие `Создать турнир` показывается только подтверждённому пользователю, как и до presentation-рефакторинга.

Существующие backend-фильтры не менялись: period, поиск по названию, дата с/по. Карточки и строки показывают статус/lifecycle, даты, игровой формат, recruitment mode, enrollment policy, default venue и CTA. Длинное описание остаётся только в карточечном режиме.

Map view строится только по `defaultVenue` с доступными координатами. Турниры без координат не исчезают из cards/list; на карте явно показывается количество элементов текущей страницы без точки. Совпадающие координаты группируются, а balloon содержит все турниры в этой точке. Viewport определяется фактическими крайними точками; для одной точки используется локальный zoom.

Связанные `defaultVenue.location.address` догружаются пачкой для текущей страницы, чтобы presentation не создавал N+1 на адресах.

## Проверки

- `TournamentIndexTest` покрывает общий `default_category`, сохранение существующих фильтров, три режима выдачи и map/unmapped поведение;
- полный CI: PHP tests + frontend build;
- production smoke после merge.

## Зависимости

Task 171.

## Ветка

`feature/173`

## Статус

Выполнено.
