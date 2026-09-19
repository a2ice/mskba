# Task 205 — Каталог участников и новая группа навигации

## Цель

Заменить самостоятельный пункт «Команды» в header на группу «Участники» и добавить публичный каталог людей в общем presentation-формате категорий MSKBA.

## Header

«Участники» — dropdown по аналогии с «Мероприятия»:

1. Игроки → `/players`;
2. Тренеры → `/coaches`;
3. Все участники → `/participants`;
4. Команды → `/teams`.

`/players` и `/coaches` используют тот же ParticipantCatalogController и тот же Blade, что `/participants`, но задают preset role.

## Каталог

Используется `theme::layouts.default-category`, после сверки с каталогами площадок, мероприятий, турниров, команд и секций:

- breadcrumbs + компактный H1;
- sidebar accordion «Участники / Фильтры»;
- toolbar search;
- cards/list switcher;
- mobile filter modal;
- стандартная пагинация;
- map-mode отсутствует, поскольку у participant entity нет географического смысла.

На общей странице доступен role filter по всем значениям `UserParticipationRoleEnum`. Search выполняется только по уже разрешённой публичной projection имени/никнейма.

## Privacy

Каталог не сериализует User/Profile целиком. `PublicUserProfileService::catalogEntry()` проверяет:

- canonical identity;
- confirmed status;
- отсутствие block/soft-delete;
- DISCOVERABILITY;
- PROFILE + конкретный ROLE_*;
- отдельную приватность AVATAR;
- существующее исключение public coach для действующего тренера открытой секции.

Ответ viewer-dependent и помечен `private, no-store`.

## Проверки

- route aliases `/players`, `/coaches`, `/participants`;
- header/sidebar navigation;
- default-category shell;
- preset filters players/coaches;
- произвольный role filter;
- search;
- discoverability/role privacy;
- cards/list.
