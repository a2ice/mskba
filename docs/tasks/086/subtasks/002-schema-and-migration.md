# 002 - Целевая схема и стратегия миграции

## Цель

Спроектировать таблицы, ключи, индексы, enum, soft delete и переход данных до изменения runtime.

## Статус

Завершено для локальной разработки; deployment strategy VDS отложена до релиза.

## Работы

- ER-схема tournaments, tournament_teams и tournament_matches;
- уточнение events/games и constraints игровых таблиц;
- route identifier без уникальности alias;
- Media relations;
- проверка наличия production-данных;
- выбор fresh-schema или expand/migrate/contract;
- dry-run и контрольные суммы для переходного варианта.

## Приёмка

- схема поддерживает standalone, tournament и training games;
- невозможно связать Match/Game/Side из разных агрегатов;
- миграционная стратегия не допускает тихой потери данных.

## Промежуточный результат

Таблицы, ключи, индексы, constraints и fresh-seed подход описаны в
[целевой схеме соревнований](../../../specification/competition-schema.md). Локальная реализация не
зависит от VDS. Перед deployment выполняется отдельный аудит и выбирается reset либо
expand/migrate/contract.
