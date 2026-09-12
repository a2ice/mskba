# 171 — Ввести стандарт `default_category` и перевести на него каталог площадок

## Цель

Зафиксировать единый presentation-pattern для однотипных публичных каталогов MSKBA (`Площадки`, `Мероприятия`, `Турниры`, `Команды`, `Секции`) и первым consumer перевести на него `/venues`.

`default_category` унифицирует только оболочку страницы. Доменные фильтры, карточки, карта, права и business-flow остаются у конкретного bounded context.

## Реализовано

В Task 171 сделано:

- добавлен общий layout `theme::layouts.default-category`;
- каталог использует полную ширину стандартного `inner` без прежнего локального ограничения `1240px`;
- desktop layout построен как `sidebar + content`;
- sidebar и toolbar фиксируются под header при прокрутке;
- desktop-фильтры площадок перенесены в sidebar;
- на `<=768px` desktop-фильтры скрываются, а в toolbar появляется icon-action `Фильтры`, открывающий modal;
- поиск остаётся первым элементом toolbar;
- реализованы режимы `cards|list|map`;
- `cards` является режимом по умолчанию;
- основной view-switcher icon-only, а варианты `Карточками / Списком` показываются текстом внутри dropdown;
- карта остаётся отдельной icon-action;
- search/filter/view query-state сохраняется между действиями;
- Venue-specific карта и Yandex Maps не перенесены в shared shell;
- карточка/строка, filters и navigation площадок вынесены в отдельные partials;
- добавлена официальная спецификация `docs/specification/default-category.md`.

## Утверждённый базовый sidebar `/venues`

Навигация:

- `Все площадки`;
- `Мои площадки` — только для авторизованного пользователя;
- `Добавить площадку` — напрямую для авторизованного пользователя, через auth-entry для гостя.

Ниже отдельным блоком располагаются фильтры:

- тип площадки;
- состояние;
- доступ.

`Популярные` и `Рядом со мной` в базовую версию не добавляются до появления отдельной корректной продуктовой логики. Состояние и условия доступа остаются фильтрами, а не навигационными пунктами.

## Архитектурные правила

Shared `default_category` отвечает за:

- shell/grid/responsive;
- sticky sidebar/toolbar;
- slots для навигации, фильтров, поиска, actions и результатов;
- общий view switcher;
- mobile filter entrypoint;
- синхронизацию view-state.

Consumer отвечает за:

- набор и backend-валидацию фильтров;
- card/list partials;
- map data/rendering;
- create/edit permissions;
- domain-specific badges/meta/empty states.

Не допускается превращать `default_category` в mega-component, который знает одновременно про Venue, Event, Tournament, Team и SportsSection.

## Проверки

Перед merge PR #176 GitHub Actions успешно выполнил:

- PHP tests;
- production compose validation;
- frontend dependencies installation;
- `npm run build`.

Production smoke-check выполняется после автодеплоя: desktop, `768px`, mobile и Telegram Mini App.

## Зависимости

Нет. Task 171 является foundation для Tasks 172–174 и 178.

## Ветка

`feature/171`

## Merge

PR #176 → `main`.

## Статус

Выполнено и вмержено в `main` 12.09.2026.
