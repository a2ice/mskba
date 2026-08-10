# 086 - Дорожная карта

## Принцип поставки

Рефакторинг выполняется вертикальными проверяемыми срезами. Каждая подзадача имеет отдельный scope,
критерии приёмки и решение о коммите. Следующая подзадача не должна маскировать регрессию предыдущей.

## Последовательность

1. Утвердить глоссарий, ownership, lifecycle и решения из `decisions.md`.
2. Утвердить целевую схему и стратегию данных.
3. Стабилизировать и принять незавершённые изменения 083–085 либо явно включить их в новую базу.
4. Нормализовать Event/Game и единый standalone flow.
5. Ввести форматы и правила игры.
6. Реализовать Tournament foundation и ACL.
7. Реализовать admission, TournamentEntry и ручные матчи турнира.
8. Добавить balanced formation с drag-and-drop correction и круговой генератор.
9. Добавить назначение матча с созданием Event/Booking/Game.
10. Подключить lifecycle, статистику и таблицу турнира.
11. Собрать публичный и управляющий UI.
12. Выполнить миграцию/seed, regression и документирование.

## Зависимости

```text
001 -> 002 -> 003 -> 004 -> 005
                         -> 006 -> 007 -> 008 -> 008a -> 009
                                              -> 010
                         -> 011 <-------------/
                              -> 012
```

## Контрольные точки

### Gate A — модель

Утверждены решения D01–D14 и закрыты обязательные открытые вопросы. До Gate A код схемы не меняется.

### Gate B — данные

Подтверждено наличие или отсутствие production-данных. Выбран fresh-schema либо переходные миграции.

### Gate C — Event/Game

Standalone game работает end-to-end без Tournament и без двойного UI.

### Gate D — Tournament core

Tournament, команды, ответственные и ручные Match работают без генератора.

### Gate E — scheduling

Match атомарно получает Event, Booking и Game с проверкой площадки и команд.

### Gate F — sports runtime

Турнирная Game проходит start/live/end/confirm и обновляет standings.

### Gate G — release

Миграция, redirects, seed, regression, build и acceptance завершены.

## Общая стратегия проверки

- unit: value objects, transitions, generator, standings;
- database: FK, unique, cross-aggregate invariants и soft delete;
- feature: CRUD, ACL, scheduling, lifecycle, statistics;
- architecture: зависимости модулей и отсутствие бизнес-логики в контроллерах;
- frontend: build и ручные mobile/desktop сценарии;
- concurrency: параллельный start, score, reorder, cancel и confirm.
