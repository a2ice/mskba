# 172 — Перевести каталог мероприятий на `default_category`

## Цель

После завершения Task 171 перевести `/events` на общий presentation-pattern `default_category`, не меняя доменную модель Event и существующие правила приватности/статусов.

## Требования

- использовать общий shell, sidebar, sticky toolbar, mobile filter popup и `cards|list|map`;
- default view — `cards`;
- сохранить текущие фильтры: период, тип, статус, даты, итог прошедшего мероприятия, площадка, наличие mini-games;
- quick-фильтры больше не должны жить отдельной несовместимой системой: на desktop соответствующие условия располагаются в sidebar, на mobile — в filter popup;
- поиск остаётся в toolbar с placeholder `Название, описание или площадка`;
- create action сохранить с текущим auth redirect;
- текущие карточки Event адаптировать к двум представлениям: компактная строка `list` и сеточная карточка `cards`;
- `map` строить по координатам площадок. Если несколько мероприятий относятся к одной точке, popup/cluster должен позволять увидеть все соответствующие мероприятия, а не терять записи;
- map view не должен раскрывать location приватного/недоступного Event сверх уже разрешённой публичной выборки;
- pagination/query state сохраняют `view`, search и filters.

## Фактическая реализация

`/events` переведён на `theme::layouts.default-category` и использует тот же presentation-contract, что `/venues`: breadcrumbs, компактный H1, accordion-sidebar, sticky toolbar, desktop filters, mobile filter modal и режимы `cards|list|map`.

Sidebar зафиксирован в минимальном варианте, который поддерживается существующей доменной моделью:

- `Предстоящие`;
- `Прошедшие`;
- `Создать мероприятие`.

`Мои мероприятия` сознательно не добавлялись: отдельного query-scope для такого каталога пока нет, а декоративная ссылка без реального поведения противоречила бы принципу `default_category`.

Сохранены фильтры `period`, `type`, `status`, `date_from`, `date_to`, `outcome`, `venue_id`, `has_mini_games` и текстовый поиск. Старый отдельный quick-filter bar удалён из presentation-flow. Boolean-фильтр mini-games использует фирменный `form-toggle`.

Сохранено существующее discovery-поведение каталога: при первом входе без явных query-параметров frontend добавляет `type=games`, а для предстоящих мероприятий — текущую московскую дату в `date_from`. Переключение между `Предстоящие` и `Прошедшие` очищает несовместимые границы дат и outcome, сохраняя остальные применимые условия.

Карточки вынесены в `pages/events/partials/catalog-item.blade.php` и имеют два presentation-режима. В mobile list сохраняются ключевые данные для выбора: тип/итог, площадка и адрес, дата/время, число участников и наличие mini-games.

Map view лениво инициализируется отдельным `event-catalog.js`. Viewport определяется фактическими координатами текущей выдачи согласно общему правилу интерактивных карт. Мероприятия с одинаковыми координатами объединяются в одну логическую точку, внутри которой показываются все соответствующие Event текущей выдачи.

Map JSON формируется исключительно из `$events`, уже возвращённых `ListEventsHandler`. Поэтому карта наследует те же правила visibility/status/actor access и не создаёт отдельный обход privacy-фильтрации. Отдельный popup карты конкретной площадки из карточки сохранён.

Pagination использует существующий `withQueryString()`, а общий view-switcher синхронизирует `view` с URL и desktop/mobile filter forms.

## Проверки

Добавлен `EventCatalogPresentationTest`, который проверяет общий shell, sidebar/filters/views и отсутствие приватного Event в публичной map-выдаче. Существующие Event workflow tests сохранены. CI проходит PHP tests и frontend build.

После merge требуется ручной production smoke для desktop/mobile и трёх режимов отображения.

## Зависимости

Task 171.

## Ветка

`feature/172`

## Статус

Выполнено.
