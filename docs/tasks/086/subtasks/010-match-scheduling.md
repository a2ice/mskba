# 010 - Назначение матча и бронирование

## Цель

Атомарно превратить TournamentMatch в запланированную Event/Game на выбранной площадке.

## Статус

Реализовано и локально проверено 2026-08-08. Следующий срез — 011, lifecycle, результаты,
standings и UI.

## Работы

- выбор даты, длительности и Venue;
- проверка границ Tournament;
- проверка Venue availability;
- проверка пересечений обеих Team;
- создание Event, VenueBooking, Game, sides и roster snapshot;
- наследование format с возможностью override;
- запись Match.game_id;
- перенос ещё не начатой игры.

## Приёмка

- частичный агрегат не остаётся после ошибки;
- недоступная площадка или занятая команда блокирует назначение;
- Game sides соответствуют Match teams;
- standalone и tournament Game используют один runtime.

## Реализация

- управляющий UI использует общий предикативный Venue selector с проверкой выбранного времени;
- назначение под единым transaction boundary создаёт Event, VenueBooking, Game, две GameSide,
  EventParticipant и snapshot GameRosterEntry, после чего связывает TournamentMatch.game_id;
- состав снимка берётся из TournamentEntryMember, поэтому одинаково поддержаны постоянные команды,
  balanced-команды и участники 1×1;
- формат наследуется от Tournament, но организатор может выбрать 1×1, 3×3 или 5×5 для матча;
- для баскетбола поддержан whole-game либо 2/4 периода;
- проверяются будущая дата, границы Tournament, доступность Venue, минимальный состав и пересечения
  игроков обеих сторон с другими играми;
- перенос ещё не начатой Game атомарно обновляет Event, VenueBooking и scheduled timestamps Game;
- единый порядок блокировок `Venue -> Tournament -> TournamentMatch -> Game/Event/Booking` снижает
  риск deadlock при параллельном бронировании и изменении турнира;
- EventChanged отправляется только после успешного commit.

Проверено: профильный и смежный регресс `67 tests / 657 assertions`, Pint, production Vite build,
маршруты schedule/reschedule и `git diff --check`.
