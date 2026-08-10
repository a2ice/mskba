# 005a - Периодный runtime игры

## Цель

Поддержать Game без деления и Game из двух либо четырёх периодов с общей и периодной статистикой.

## Статус

Выполнено.

## Решения

- `GameTimingModeEnum`: `whole_game|periods`;
- `GamePeriodsCountEnum`: `two|four`, nullable при whole_game;
- `GamePeriod` — дочерняя сущность Game, не aggregate root;
- периодная Game создаёт строки периодов до старта;
- GameAction периода обязательно ссылается на активный GamePeriod;
- GamePlayerStatistic остаётся общим итогом Game;
- периодные срезы рассчитываются по GameAction и итоговым score snapshots GamePeriod;
- параметры периодов блокируются после actual start;
- на последнем периоде завершение периода завершает фактическое проведение Game.

## Работы

- enums, migration, models и invariants;
- создание периодов из preset/ручной настройки;
- start/end period lifecycle;
- привязка действий и коррекций счёта к активному периоду;
- period state в HTTP API;
- management controls и live/read-only отображение;
- fresh migration, seed и regression.

## Приёмка

- whole_game сохраняет текущий flow без технических периодов;
- periods допускает только 2 или 4;
- одновременно активен максимум один период Game;
- действие периодной Game невозможно записать вне активного периода;
- общая статистика не дублируется и доступна вместе с периодным срезом;
- завершить Game до закрытия всех периодов нельзя.

## Результат

- добавлены timing/count/status enums и дочерняя модель GamePeriod;
- PostgreSQL CHECK защищает whole_game/null и periods/2|4;
- периодные строки создаются до старта и после actual start конфигурация блокируется общим lifecycle;
- старт Game автоматически запускает первый период;
- между периодами оперативный ввод закрыт, следующий период запускается явно;
- последний период завершается атомарно с фактическим окончанием Game;
- GameAction и score correction периодной игры получают ссылку на активный GamePeriod;
- GamePlayerStatistic остаётся общим итогом, периодный срез воспроизводится из GameAction;
- GamePeriod хранит cumulative score snapshot, UI показывает очки отдельного периода как delta;
- lifecycle API и management UI показывают текущий период и доступные переходы;
- live-страница определяет LIVE по actual timestamps и показывает общую/периодную статистику;
- локальная PostgreSQL-миграция применена, demo-seeder обновлён и перезапущен;
- Tournament/Event/Database профиль: 57 тестов, 563 assertions; frontend build успешен.
