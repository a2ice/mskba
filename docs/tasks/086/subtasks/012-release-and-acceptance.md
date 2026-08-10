# 012 - Миграция, сидеры и финальная приёмка

## Цель

Безопасно подготовить новую модель к эксплуатации и подтвердить основные пользовательские сценарии.

## Статус

Автоматическая часть и локальные fixtures реализованы и проверены 2026-08-08. Финальный manual
desktop/mobile acceptance выполняется владельцем проекта по `../acceptance.md`.

## Работы

- применить утверждённую data strategy;
- legacy redirects;
- production-safe base seeder;
- local acceptance seeder standalone/training/round-robin;
- regression, architecture и database tests;
- frontend build;
- ручной desktop/mobile acceptance;
- обновление project/specification/README и эксплуатационных команд.

## Приёмка

- fresh install и upgrade-путь проходят;
- orphan/cross-aggregate checks равны нулю;
- standalone, training и Tournament проходят end-to-end;
- документация соответствует runtime;
- rollback/restore procedure описана до production deployment.

## Реализация

- production-safe `DatabaseSeeder` не содержит demo-данных;
- локальный идемпотентный `TournamentAcceptanceSeeder` создаёт standalone, training и круговой
  Tournament с четырьмя составами, шестью матчами, двумя Game и одним confirmed result;
- добавлена команда `make acceptance-seed`;
- добавлен локальный `TournamentLabSeeder` и destructive-команда `make tournament-lab-fresh`:
  после чистой миграции остаются 10 команд с составами 5–10 игроков, 75 заполненных игровых
  профилей, 4 подтверждённые московские площадки и ни одного готового Tournament/Event/Game;
- эмблемы команд и вымышленные аватары игроков хранятся в локальных fixture assets и не требуют
  внешней сети во время seed;
- clean install проверен на отдельной временной SQLite database, рабочая local DB не сбрасывалась;
- повторный запуск acceptance seeder не создаёт дубликаты;
- автоматические orphan/cross-aggregate проверки покрывают Event.primaryGame, Tournament entries,
  TournamentMatch.game и Game roster/side ownership;
- upgrade-path локальной БД проверен через `migrate:status`: pending migrations отсутствуют;
- инструкции browser acceptance, deployment и backup/restore вынесены в `../acceptance.md`;
- аудит VDS явно оставлен отдельным pre-production gate и локально не выполнялся.

## Результат автоматической проверки

- целевой regression Event / Tournament / database integrity: 68 тестов, 637 assertions;
- acceptance seeder повторно выполнен в Docker на текущей local DB: 4 команды, 12 игроков,
  6 матчей, 2 назначенные игры и 1 confirmed result без дубликатов;
- scoped Pint, Vite production build и `git diff --check` проходят;
- монолитный host-прогон всего legacy test suite не является зелёным gate: вне среза 086 остаются
  старые сбои Team/Admin/Coordination и зависимость Portal tests от отсутствующего host PHP Redis.
  Они не воспроизводятся в целевом regression, но должны быть разобраны отдельной задачей.
