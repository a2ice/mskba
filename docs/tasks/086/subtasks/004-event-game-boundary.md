# 004 - Нормализация границы Event/Game

## Цель

Сделать `Event(type=game) + Game` единым пользовательским и транзакционным сценарием.

## Статус

Выполнено.

## Работы

- единый CreateStandaloneGame use case;
- одна основная Game игрового Event;
- единые операции update/cancel/complete;
- разделение организационного и фактического статусов;
- канонический публичный URL и management URL;
- сохранение внутренних игр тренировочного Event;
- redirects старых URL.

## Приёмка

- пользователь не создаёт и не управляет двумя видимыми сущностями;
- календарное время не переводит Game в live;
- бронирование остаётся у Event;
- lifecycle и статистика остаются у Game.

## Результат

- добавлен явный application entry point `CreateStandaloneGameHandler`;
- `events.primary_game_id` уникально определяет основную Game и проверяется nested-маршрутами;
- standalone Game больше не копирует `Event.starts_at/ends_at`; публичный и live UI берут её
  расписание из Event;
- подтверждение результата завершает primary Game и её Event одной транзакцией;
- отмена standalone Game проходит через отмену Event, синхронно отменяет Game и освобождает бронь;
- публичный canonical URL standalone-игры — `/events/{id-alias}`; прежний вложенный URL постоянно
  перенаправляет на него;
- вложенные игры тренировок сохраняют собственные названия, интервалы, lifecycle и nested URL;
- профиль Event/Database: 50 тестов, 504 assertions; production frontend build успешен.
