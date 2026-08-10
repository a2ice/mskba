# 008 - Команды и ручные матчи турнира

## Цель

Добавить admission flow, игровые стороны Tournament и управляемую последовательность Match.

## Статус

Выполнено.

## Работы

- настройка recruitment mode: preformed teams / individual draft;
- tournament_admissions для Team/User, application/invitation и двустороннего accept/decline;
- tournament_entries для permanent Team, assembled roster и 1×1 participant;
- tournament_entry_members как снимок состава;
- для preformed mode — фиксация accepted Team как entry без автоматического формирования;
- для individual mode — accepted pool; единый balanced preview flow выносится в 008a;
- tournament_matches с round/sequence и nullable game_id;
- ручное добавление пары;
- reorder в транзакции;
- защита начавшихся и завершённых матчей;
- публичный список команд и матчей.

## Приёмка

- candidate не дублируется в admission pool;
- в 1×1 не создаётся фиктивная permanent Team;
- Match содержит две разные TournamentEntry;
- sequence стабилен и уникален;
- назначенная/начатая Game не теряется при изменении порядка.

## Реализовано

- `Tournament.recruitment_mode`, принудительный individual mode для 1×1 и запрет смены после первой admission;
- Team/User applications и invitations с pending/accepted/declined/revoked lifecycle;
- двусторонная authorization: Tournament отвечает на application, candidate — на invitation;
- уведомления о заявках и приглашениях;
- `TournamentEntry` без фиктивной Team для 1×1 и roster snapshot для preformed Team;
- individual 3×3/5×5 admissions остаются accepted pool до balanced-среза 008a;
- ручное создание, удаление и двухфазный reorder Match без конфликта unique sequence;
- неназначенный draft Match удаляется физически и освобождает sequence; Match с Game удалять нельзя;
- публичные списки entries/matches и management UI.

## Проверка

- `php artisan test tests/Feature/Tournament --compact` — 10 tests, 76 assertions;
- `php artisan test tests/Feature/Event tests/Feature/Database tests/Feature/Tournament --compact` — 62 tests, 607 assertions;
- local migration `2026_08_08_120000_create_tournament_admissions_entries_and_matches` применена.
