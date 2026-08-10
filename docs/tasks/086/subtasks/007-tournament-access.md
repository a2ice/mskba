# 007 - Ответственные и ACL турнира

## Цель

Реализовать договорное делегирование управления Tournament.

## Статус

Выполнено.

## Работы

- tournament contract scope;
- permissions для игр, статуса, описания, staff и удаления;
- приглашение, принятие, отклонение и отзыв;
- TournamentAccess resolver;
- объединение Tournament и Event/Game permissions;
- уведомления и аудит.

## Реализовано

- в общий `ContractMembershipScopeTypeEnum` добавлен scope `tournament`;
- договор ответственного хранится в существующих `contracts`, `contract_memberships` и
  `contract_permissions`, без параллельной ACL-схемы;
- реализованы permissions для игр, статуса, описания/обложки, staff и удаления;
- `TournamentAccess` выдаёт доступ создателю либо по принятому непросроченному активному
  договору с точным permission;
- добавлены invitation, accept, decline, permission update и revoke flow, а также внутрисайтовое
  уведомление;
- mutations описания, обложки, статуса, staff и delete проверяют permission в application-слое;
- ответственный не может делегировать права, которых у него нет;
- `manage_tournament_games` зафиксирован как tournament-side capability; его пересечение с
  Event/Game ACL подключается в 010 после появления `TournamentMatch -> Game`.

## Проверка

- `php artisan test tests/Feature/Tournament --compact` — 7 tests, 54 assertions;
- `php artisan test tests/Feature/Event tests/Feature/Database tests/Feature/Tournament --compact` —
  59 tests, 585 assertions;
- `npm run build` — успешно;
- профильные тесты покрывают pending/accepted/revoked, scope isolation и granular permissions.

## Приёмка

- права действуют только у принятого активного контракта;
- creator имеет полный ownership access;
- право Tournament не открывает чужие Event, Team или Tournament;
- каждый mutation endpoint проверяет application-level ACL.
