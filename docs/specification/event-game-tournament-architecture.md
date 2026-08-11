# ADR: Event, Game и Tournament

## Статус

Принято 7 августа 2026 года в задаче 086. Реализация выполняется поэтапно; до её завершения
отдельные части runtime продолжают соответствовать предыдущему ADR задачи 084.

Этот ADR заменяет целевую часть документа [Разделение Event и Game](event-game-separation.md), но
не отменяет уже принятые там решения о принадлежности сторон, составов, статистики и игровых
действий сущности Game.

## Оглавление

- [Контекст](#контекст)
- [Решение](#решение)
- [Event](#event)
- [Game](#game)
- [Tournament](#tournament)
- [TournamentMatch](#tournamentmatch)
- [Варианты игр](#варианты-игр)
- [Ownership данных](#ownership-данных)
- [Lifecycle](#lifecycle)
- [Права](#права)
- [Маршруты и UI](#маршруты-и-ui)
- [Транзакции и блокировки](#транзакции-и-блокировки)
- [Следствия](#следствия)

## Контекст

MSKBA должен поддерживать самостоятельные игры, тренировки с несколькими внутренними играми и
турниры с упорядоченными матчами. Площадка, бронь и публикация нужны любому календарному событию,
а счёт, состав и статистика относятся только к спортивному проведению.

Предыдущая модель правильно вынесла спортивные данные из Event в Game, но оставила несколько
неоднозначностей:

- standalone game технически и визуально воспринималась как Event плюс вложенная Game;
- календарное наступление времени смешивалось с фактическим запуском;
- одна и та же связь Event/Game использовалась и для основной игры, и для внутренних игр тренировки
  без явного различия;
- будущий Tournament описывался как возможная надстройка, но не имел утверждённого агрегата,
  участников и матчей;
- прямой `games.tournament_id` не позволяет хранить неназначенный матч сетки.

## Решение

Используется композиция, а не наследование моделей и не одна широкая таблица:

```text
Event — календарная и организационная оболочка
Game — спортивное проведение внутри Event
Tournament — контейнер соревнования
TournamentMatch — место встречи в структуре Tournament
```

Связи:

```text
Event 1 ─── 0..N Game
Tournament 1 ─── N TournamentMatch
TournamentMatch 0..1 ─── 1 Game
```

Для пользователя `Event(type=game) + primary Game` является одной самостоятельной игрой. Разделение
остаётся внутренней архитектурой и не должно заставлять пользователя переходить между двумя
карточками для обычного сценария.

## Event

Event отвечает за:

- публичное название, alias и route identifier;
- тип `game`, `training`, `game_training` или `open_training`;
- организационный статус и видимость;
- создателя/организатора;
- площадку;
- планируемые начало и окончание;
- бронь;
- регистрацию участников;
- публикации, уведомления и общие media;
- краткое и полное публичное описание.

`ends_at` обязателен для Event с площадкой, потому что без закрытого интервала нельзя проверить
доступность и создать корректное бронирование.

## Game

Game отвечает за:

- фактический спортивный lifecycle;
- preset формата, размеры сторон, scoring type и количество периодов;
- две GameSide;
- исторический GameRosterEntry snapshot;
- starters и captains конкретной игры;
- GameAction, текущий счёт и GamePlayerStatistic;
- победителя и подтверждение результата.

Game поддерживает `whole_game` без технических периодов и `periods` с двумя либо четырьмя дочерними
GamePeriod. GamePeriod не является aggregate root: lifecycle и блокировки проходят через Game.
GameAction в периодном режиме относится к активному GamePeriod, а GamePlayerStatistic остаётся
общим итогом игрока за Game. Периодный срез строится из действий; GamePeriod хранит score snapshot.

Game не дублирует название, alias, площадку, планируемое время и публичные описания Event.

Actual timestamps недоступны для обычного ручного редактирования. `actual_started_at` и
`actual_ended_at` устанавливаются сервером при lifecycle-переходах. Исторический импорт является
отдельным административным use case.

## Tournament

Tournament является самостоятельным агрегатом и не является Event. Он отвечает за:

- публичный паспорт и даты соревнования;
- модерационный статус;
- общий preset формата;
- команды-участники;
- договорных ответственных;
- матчи, туры и последовательность;
- агрегированную турнирную таблицу.

Создавать Tournament может подтверждённый незаблокированный пользователь. По умолчанию создаваемый
им Tournament имеет статус `confirmed`. Фазы `upcoming`, `in_progress` и `finished` вычисляются по
датам и не записываются как модерационные статусы.

Boolean-поле `accepts_unconfirmed_participants` применимо только к
`recruitment_mode = individual_draft` и по умолчанию равно `false`. Create/update use cases
нормализуют его в `false` для режима готовых команд. При персональной заявке admission service под
блокировкой Tournament проверяет статус пользователя до создания `TournamentAdmission`; отказ не
создаёт запись и уведомление. Проверка не применяется к приглашениям организатора.
Персональная application-заявка также хранит непустой JSON-массив ролей из
`player|coach|manager`; каждый элемент и отсутствие повторов валидируются сервером. Для командных
заявок и приглашений поле остаётся nullable. Решение организатора применяется ко всей заявке и
всему набору ролей, выборочное подтверждение отдельных ролей не предусмотрено.

## TournamentMatch

TournamentMatch существует отдельно от Game и содержит:

- Tournament;
- две разные записи TournamentTeam;
- номер тура;
- последовательность;
- nullable ссылку на Game.

Генератор `каждый с каждым` создаёт TournamentMatch, но не создаёт Event, бронь или Game. Они
появляются только при назначении времени и площадки.

Назначение матча одной транзакцией:

1. проверяет Tournament, команды, интервал и права;
2. проверяет занятость команд и площадки;
3. создаёт Event типа game;
4. создаёт VenueBooking;
5. создаёт primary Game, стороны и roster snapshot;
6. связывает TournamentMatch с Game.

## Варианты игр

### Самостоятельная игра

```text
Event(type=game)
└── primary Game
```

У Event типа game должна быть ровно одна primary Game.

### Турнирная игра

```text
TournamentMatch
└── Game
    └── Event(type=game)
```

Она использует тот же runtime, что standalone game.

### Внутренняя игра тренировки

```text
Event(type=training|game_training)
├── embedded Game
└── embedded Game
```

Внутренние Game используют площадку и бронь родительского Event. Они не создают самостоятельные
VenueBooking. Их фактические lifecycle независимы друг от друга.

`game_training` остаётся отдельным Event type. `open_training` также остаётся типом Event; отдельная
detail-сущность для него вводится только при появлении собственных полей.

## Ownership данных

| Данные | Владелец |
|---|---|
| Public title, alias, descriptions, media самостоятельной игры | Event |
| Venue, scheduled interval, booking | Event |
| Registration, visibility, Telegram publication | Event |
| Actual timestamps и sports status | Game |
| Format preset, side sizes, scoring, periods | Game |
| Sides, roster snapshot, score, actions, statistics | Game |
| Tournament title, dates, descriptions, media, status | Tournament |
| Tournament participants | TournamentTeam |
| Pair, round, sequence | TournamentMatch |

## Lifecycle

```text
Event: draft -> published -> completed
                    \-> cancelled

Game: scheduled -> in_progress -> awaiting_result -> completed
          \-> cancelled

Tournament: unconfirmed <-> confirmed -> cancelled
```

Календарные признаки не изменяют Game status. `Live` означает наличие фактического старта и
отсутствие фактического окончания.

Для Event типа game согласованные переходы Event и primary Game выполняются одним application use
case в транзакции. Observer не используется как скрытый оркестратор бизнес-процесса.

## Права

Создатель Tournament имеет полный доступ по ownership. Ответственные используют существующие
membership contracts со scope `tournament` и атомарными permissions:

- `manage_tournament_games`;
- `manage_tournament_status`;
- `manage_tournament_description`;
- `manage_tournament_staff`;
- `delete_tournament`.

Право Tournament действует только на Game, связанную с TournamentMatch этого Tournament. Оно не
разрешает управлять другими Event, Tournament или постоянным составом Team.

## Маршруты и UI

Публичный UI показывает самостоятельную игру как одну карточку. Техническое разделение Event/Game
не дублирует заголовки и не создаёт две конкурирующие страницы.

Tournament использует ID+alias URL:

```text
/tournaments/{id}-{alias}
```

ID является источником идентичности; alias может повторяться. Канонический публичный URL
standalone-игры — `/events/{id}-{alias}`. Вложенный `/events/{event}/games/{game}` применяется для
игр тренировки; старый вложенный URL standalone-игры постоянно перенаправляется на Event URL.

Управление спортивным проведением остаётся отдельным surface
`/events/{event}/games/{primaryGame}/manage`, но не является второй публичной карточкой. Изменение
публичного названия, площадки и планируемого интервала выполняется через Event, а счёт, состав,
lifecycle и статистика — через management Game.

Публичный и управляющий контексты разделены: зритель получает read-only карточку, пользователь с
правом — явный переход в management surface.

## Транзакции и блокировки

Глобальный порядок:

```text
Tournament -> TournamentMatch -> Event -> Game -> GameSide
           -> GameRosterEntry -> GameAction/GamePlayerStatistic
```

Standalone flow начинается с Event. Reorder блокирует Tournament и Match. Назначение матча,
запуск, завершение, подтверждение результата и отмена проверяют инварианты после получения locks.
Транзакции допускают ограниченный retry при deadlock.

## Следствия

Положительные:

- одна система бронирований сохраняется для всех событий;
- standalone и tournament games используют один sports runtime;
- TournamentMatch поддерживает неназначенные матчи и будущие сетки;
- training mini-games не дублируют бронь;
- ownership полей однозначен.

Отрицательные:

- создание standalone game требует транзакционной оркестрации Event и Game;
- DB не выражает все cross-aggregate invariants без application checks;
- доступ к турнирной Game объединяет несколько источников прав;
- переход требует пересмотра маршрутов и текущих экранов.
