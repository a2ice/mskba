# 035 — Каталог и выдача глобальных и предметных прав

Статус: локальный пилот (2026-10-13). Без commit, push, deploy.

Существующие источники: `UserOperationalPermissionEnum` (5 прав), `TeamPermissionEnum` (12), `VenuePermissionEnum` (11), `EventResponsibilityPermissionEnum` (15), `TournamentPermissionEnum` (5), `SportsSectionPermissionEnum` (6). Перечисления предметных прав остаются владельцами их кодов и правил. Единый каталог агрегирует их без копирования текстовых кодов. Новое право `identity.profile_change.review` разрешено только подтверждённым SUPERADMIN (по умолчанию) и явно уполномоченным ADMIN; выдача не означает доступ к прежним заявкам. Никаких автоматических выдач после подтверждения контакта.

Изменения: группировка административного UI; инкрементальное сохранение индивидуальных прав (без перезаписи каждого snapshot); действующая схема остальных системных/договорных прав не изменяется. Более широкая миграция всех прав — после приёмки пилота.

QA: текущие права и отказы, делегирование, default-deny для admin, датировка выдачи, SQL без чрезмерных JOIN, регрессия.

## Локальный результат 2026-10-13

**Статус: на визуальной приёмке пилота, системный рефакторинг продолжается по типам объектов.**

- Центральный `PermissionCatalog` собирает оригинальные 54 определения из глобального и предметных enum, добавлен 55-й код `identity.profile_change.review` без дублирования остальных enum. Предметные права остаются привязаны к командам/площадкам/мероприятиям/турнирам/секциям; новые коды ещё не активированы для их заявок.
- Существующая административная форма прав сгруппирована на «Создание» и «Администрирование пользователей», минимальная системная роль отображается; недоступные для роли переключатели disabled. Прежние Gates и domain ACL не переписаны без необходимости.
- `AdminUpdateUserOperationalPermissionsHandler` обновляет только изменённые snapshots, не создаёт дублирующий default-allowed snapshot. Review личных данных доступно подтверждённым superadmin по умолчанию, подтверждённым admin — только по явному назначению, остальным недоступно. Выдача по подтверждённому контакту это право не затрагивает.
- Безопасная историческая граница: `review_permission_cursors` с сохранённым `MAX(profile_change_requests.id)` при выдаче. Уже имевшие право superadmin получают baseline 0 в миграции, новые superadmin — baseline до следующей новой заявки/при назначении роли. Старые заявки по умолчанию не открываются даже при совпадении временных меток. При отзыве/понижении роли сохраняется tombstone.
- Тесты ролей, отзыва, поздней выдачи, повторного сохранения и запрета нижним ролям; обязательная проверка в backend. См. [архитектурный контракт](../../../specification/request-permissions-and-threads.md).

## За пределами пилота

Для дополнительных разрешений `venue.*`, `moderation.*`, `booking.*` необходим поэтапный переход от прямых Gate/role проверок к каталогу и договорным scoped ACL без незаметного изменения дефолтов. Не выдавать перечень объявленных enum за полностью интегрированный access engine. Масштабная административная матрица всех scoped-разрешений отдельным этапом.

## Local pilot outcome (2026-10-13)

Status: ready for local visual review; migration of other domain access rules is NOT complete.

- `PermissionCatalog` aggregates all existing global and scoped enum definitions, without copying permission code strings. Existing object-scoped ACL remains intact.
- New `identity.profile_change.review`: confirmed SUPERADMIN default, ADMIN only if explicitly granted, lower roles forbidden. Verification of a contact does not auto-grant sensitive rights.
- The existing admin rights editor shows groups and minimum required system role; invalid role groups are disabled. Saving unchanged permission defaults does not create redundant snapshots or reset timestamps.
- `review_permission_cursors` records last profile-request ID before granting review rights, including role promotion. Existing SUPERADMIN users keep pre-pilot history (cursor 0); newly granted users have no automatic access to older requests.
- Covered by security and permission tests. Architecture: `docs/specification/request-permissions-and-threads.md`.

Remaining scope: migrate permission checks of venue creation/revision, ownership claims, bookings and team operations one by one; do not issue nominal enum rights before their backend checks exist. Broader admin UX for object-scoped rights is separate from this pilot.

## QA after chat recovery (2026-10-13)

- Fixed regrant cursor: capture previous effective permission before mutating the Eloquent snapshot; a new grant writes the new `MAX(profile_change_requests.id)` cutoff, but an unchanged save never resets the cutoff.
- Regression test covers grant, old request, revoke, intermediate request, regrant and new request. Prior unjoined threads do not become visible again; an explicit invitation still permits one historical thread.
- Fixed malformed Blade inline `@php` followed by nested `@foreach` in legacy admin editor; 20 admin feature tests pass.
