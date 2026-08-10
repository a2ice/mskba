# 011 - Lifecycle, результаты, standings и UI

## Цель

Довести Tournament до end-to-end проведения и понятного public/manage интерфейса.

## Статус

Реализовано и локально проверено 2026-08-08. Следующий срез — 012, release/fresh-seed и
финальная приёмка в браузере.

## Работы

- start/live/end/confirm турнирной Game;
- проекция состояния TournamentMatch;
- standings только из confirmed statistics;
- базовая формула побед/поражений и tie-break после согласования;
- публичные вкладки Overview/Teams/Games/Table;
- manage вкладки Main/Teams/Matches/Schedule/Staff/Status;
- mobile-first CTA и breadcrumbs Tournament → Game.

## Приёмка

- результат учитывается ровно один раз;
- исправление неподтверждённого результата не портит таблицу;
- зритель не видит mutation controls;
- ответственный видит только разрешённые разделы.

## Реализация

- Tournament Game использует существующий lifecycle start/live/end/confirm и общий экран управления;
- `manage_tournament_games` проецируется в игровые права roster/score/statistics/complete только для
  Game, связанной с матчем этого Tournament;
- standings является вычисляемой read-model и учитывает только `statistics_status=confirmed`;
- формула v1: победа 2, ничья 1, поражение 0; tie-break — очки, победы, разница, забитые,
  затем название;
- результат не денормализуется в TournamentMatch, поэтому не может быть учтён дважды;
- публичная страница содержит Overview, Teams/Players, Games и Table, но не mutation controls;
- игра показывает контекст турнира, ссылку назад и использует обычную live-страницу;
- manage navigation формируется только из доступных пользователю разделов и сохраняет серверные
  ACL-проверки каждого действия.

Проверено: профильный и смежный регресс `69 tests / 667 assertions`, Pint, production Vite build
и `git diff --check`.
