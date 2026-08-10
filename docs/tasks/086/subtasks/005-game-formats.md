# 005 - Форматы и правила игры

## Цель

Разделить preset, размеры сторон, scoring и баскетбольские периоды.

## Статус

Базовая Game-часть выполнена. Периодный runtime вынесен в обязательную подзадачу
[005a](005a-game-period-runtime.md). Наследование Tournament preset подключается после появления
TournamentMatch в подзадачах 008–010.

## Работы

- enum preset: basketball_5x5, streetball_3x3, streetball_1x1, custom;
- nullable preset и наследование Tournament format;
- side_a_size/side_b_size;
- scoring basketball/streetball;
- periods_count для basketball;
- UI defaults и ручное редактирование;
- серверные invariants и отображение.

## Приёмка

- preset выставляет значения по умолчанию;
- ручная правка переводит preset в custom;
- scoring корректно рассчитывает броски;
- историческая Game не меняется при изменении Tournament.

## Результат

- добавлен `GameFormatEnum`: `basketball_5x5`, `streetball_3x3`, `streetball_1x1`, `custom`;
- Game хранит nullable `format`, сохраняя независимый snapshot настроек; смысл `periods_count`
  уточняется в 005a через отдельный timing mode;
- preset на форме выставляет размеры сторон, scoring и четыре периода для basketball 5×5;
- ручное изменение размеров, scoring или периодов переключает UI на `custom`, а сервер независимо
  нормализует несовпадающую конфигурацию в `custom`;
- периоды допустимы только при basketball scoring; для streetball сервер сохраняет null;
- прежние HTTP-клиенты без `game_format` совместимы: request выводит preset из размеров и scoring;
- embedded Game получает `custom`, пока для неё явно не выбран preset;
- существующий расчёт бросков продолжает использовать snapshot `scoring_type` конкретной Game;
- локальная миграция применена, demo-seeder повторно выполнен;
- профиль Event/Database: 51 тест, 513 assertions; frontend build успешен.

При создании Game из TournamentMatch выбранный или унаследованный preset будет скопирован в Game.
Последующее изменение Tournament не должно обновлять уже созданную Game — этот контракт будет
защищён интеграционным тестом scheduling-среза.
