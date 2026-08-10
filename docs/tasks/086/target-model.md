# 086 - Целевая доменная модель

## Оглавление

- [Контексты](#контексты)
- [Связи](#связи)
- [Ownership](#ownership)
- [Инварианты](#инварианты)
- [Lifecycle](#lifecycle)
- [Права](#права)
- [Конкурентный доступ](#конкурентный-доступ)

## Контексты

### Event

Организация события: публичная идентичность, тип, площадка, планируемый интервал, бронь, регистрация,
видимость, публикации, организационный статус и общие медиа.

### Game

Спортивное проведение: формат, две стороны, снимок составов, starters, captains, фактический
lifecycle, счёт, журнал действий, статистика, победитель и подтверждение результата.

### Tournament

Соревнование: паспорт турнира, команды-участники, договорные ответственные, схема, матчи,
последовательность и агрегированная таблица.

## Связи

```text
Tournament 1 ─── N TournamentAdmission
Tournament 1 ─── N TournamentEntry
TournamentEntry 1 ─── N TournamentEntryMember N ─── 1 User
TournamentEntry 0..1 ─── 1 Team
Tournament 1 ─── N TournamentMatch
TournamentMatch 0..1 ─── 1 Game
Event 1 ─── 0..N Game
Event 1 ─── 0..1 VenueBooking
Game 1 ─── 2 GameSide
Game 1 ─── N GameRosterEntry
Game 1 ─── N GameAction
Game 1 ─── N GamePlayerStatistic
```

`Event(type=game)` вводит application-инвариант ровно одной основной Game. Для training Event
отношение остаётся 0..N.

## Ownership

| Данные | Владелец |
|---|---|
| Название, alias и публичный URL игры | Event |
| Площадка и бронирование | Event |
| Планируемое начало и окончание | Event |
| Регистрация зрителей/участников мероприятия | Event |
| Формат, размеры сторон и scoring | Game |
| Фактическое начало и окончание | Game |
| Команды и снимок состава | Game |
| Счёт, действия и статистика | Game |
| Паспорт и даты турнира | Tournament |
| Заявки/приглашения Team или User | TournamentAdmission |
| Игровые стороны турнира | TournamentEntry |
| Снимок состава стороны | TournamentEntryMember |
| Тур, порядок и пара команд | TournamentMatch |
| Дата/площадка турнирного матча | Event связанной Game |

## Инварианты

### Tournament

- создаётся подтверждённым незаблокированным пользователем;
- title обязателен, alias не уникален и используется вместе с ID;
- alias задаётся при создании и после этого не изменяется, чтобы канонический публичный URL оставался стабильным;
- ends_on отсутствует либо не раньше starts_on; Tournament хранит календарные даты без времени;
- status по умолчанию confirmed;
- recruitment mode: `preformed_teams` либо `individual_draft`;
- для формата 1×1 recruitment mode фиксируется как `individual_draft`;
- recruitment mode не меняется после первой admission;
- в календарную дату начала admissions остаются открыты до фактического старта первой Game;
- admissions закрываются после фактического старта первой Game либо если starts_on уже прошла;
- в preformed mode одна Team входит в Tournament не более одного раза;
- в individual mode один User входит в утверждённый пул не более одного раза;
- отменённый Tournament не принимает новые команды и матчи;
- soft delete не удаляет спортивную историю.

### Tournament admission и entries

- admission candidate — Team в `preformed_teams` либо User в `individual_draft`;
- `application` подаёт candidate, а Tournament принимает/отклоняет; `invitation` создаёт Tournament, а candidate
  принимает/отклоняет;
- только accepted admission попадает в пул формирования;
- preformed entry ссылается на permanent Team, но хранит собственный roster snapshot;
- assembled entry не создаёт permanent Team и существует только внутри Tournament;
- для 1×1 entry содержит ровно одного member и публично называется участником, а не командой;
- balanced preview можно пересчитывать и вручную изменять drag-and-drop до фиксации entries;
- отдельный random formation mode не предоставляется;
- после создания матчей составы не пересобираются автоматически.

### TournamentMatch

- обе TournamentEntry различны и входят в один Tournament;
- sequence уникален внутри Tournament;
- связанная Game принадлежит ровно одному TournamentMatch;
- команды GameSide соответствуют командам матча;
- начавшийся матч нельзя перепривязать к другой Game или паре команд.

### Event/Game

- standalone game создаёт Event и Game атомарно;
- Event type game имеет одну основную Game;
- Game имеет ровно две стороны A/B;
- у каждой стороны выбранных игроков не меньше размера стороны;
- starters ровно столько, сколько требует размер стороны;
- запасные не ограничиваются размером стороны;
- капитан ровно один на сторону и входит в состав;
- после actual start параметры, стороны и снимок состава неизменяемы;
- live mutations разрешены только между actual start и actual end;
- подтверждение результата разрешено после actual end;
- статистика подтверждается для всего выбранного состава;
- отменённые игры не участвуют в агрегатах.

### Время турнирной игры

- календарная дата Event.starts_at не раньше Tournament.starts_on;
- при Tournament.ends_on календарная дата Event.ends_at не позже неё;
- Event.ends_at позже Event.starts_at;
- площадка свободна на весь интервал;
- ни одна из двух команд не участвует в пересекающейся игре этого Tournament.

## Lifecycle

```text
Event: draft -> published -> completed
                    \-> cancelled

Game: scheduled -> in_progress -> awaiting_result -> completed
          \-> cancelled

Tournament: unconfirmed <-> confirmed -> cancelled
```

Календарные признаки upcoming/live/past не заменяют lifecycle Game. Game становится live только после
успешного перехода в `in_progress` с серверным `actual_started_at`.

## Права

Tournament использует membership contract со scope `tournament` и атомарными permissions:

- manage_tournament_games;
- manage_tournament_status;
- manage_tournament_description;
- manage_tournament_staff;
- delete_tournament.

Доступ к турнирной Game объединяет ownership/permissions Event и разрешение Tournament, но не
распространяется на другие Event или постоянное управление Team.

## Конкурентный доступ

Глобальный порядок блокировок:

```text
Tournament -> TournamentMatch -> Event -> Game -> GameSide
           -> GameRosterEntry -> GameAction/GamePlayerStatistic
```

Standalone-операции начинают с Event. Перестановка матчей блокирует Tournament и затронутые Match.
Назначение матча одной транзакцией создаёт Event, VenueBooking, Game, sides, roster snapshot и связь
Match. Подтверждение результата идемпотентно и использует версию статистики; последовательность
GameAction защищена unique `(game_id, sequence)`.
