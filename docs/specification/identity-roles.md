# Identity Roles

## Оглавление

- [Назначение](#назначение)
- [Системная роль пользователя](#системная-роль-пользователя)
- [Роли участия пользователя](#роли-участия-пользователя)
- [Профили участия](#профили-участия)
- [Связь и ограничения](#связь-и-ограничения)

## Назначение

Документ фиксирует техническую модель ролей пользователя в identity-слое.

## Системная роль пользователя

Системная роль хранится в `users.system_role` и описывается enum `UserSystemRoleEnum`.

Поле `system_role` nullable. `null` означает, что у пользователя нет специальной системной роли.

Текущие системные роли:

- `superadmin`;
- `admin`;
- `user`;
- `moderator`;
- `editor`;
- `system`.

`UserSystemRoleEnum` содержит `numericValue()` и `atLeast()`, чтобы сравнивать уровень системной роли.

Для dev/VDS-администрирования есть консольная команда:

```bash
php artisan identity:set-system-role {role} {username}
```

Makefile-обертка:

```bash
make sr superadmin user_login
make sr admin user_login
make ENV=prod sr superadmin user_login
```

Команда ищет пользователя по `users.username`, валидирует роль через `UserSystemRoleEnum` и обновляет `users.system_role`.

## Роли участия пользователя

Роли участия вынесены в модель `UserParticipationRole` и таблицу `user_participation_roles`.

Текущие роли участия:

- `player`;
- `coach`;
- `referee`;
- `statistician`;
- `media`;
- `venue_related`.

Статусы роли участия:

- `active`;
- `inactive`.

Источники назначения роли:

- `user`;
- `flow`;
- `seeder`;
- `other`.

В flow регистрации выбор роли участия необязателен. Если форма регистрации передает валидную роль из `UserParticipationRoleEnum` в поле `role` или совместимом alias `participantRole`, `RegisterUserHandler` создает связанную активную запись `UserParticipationRole` с `assigner = user`, `assigned_by = users.id` созданного пользователя и комментарием о выборе при регистрации.

Роль участия включает:

- предметную роль пользователя;
- статус роли;
- дату назначения;
- период действия;
- источник назначения;
- пользователя, назначившего роль, если он есть;
- комментарий.

Самообслуживание ролей доступно через `GET /account/roles` и
`PATCH /account/roles`. Изменение выполняет
`UpdateUserParticipationRolesHandler` в транзакции с блокировкой пользователя:
выбранные роли создаются или повторно активируются, снятые роли получают статус
`inactive`. Записи не удаляются, поэтому история назначения сохраняется.

## Профили участия

Роли участия не хранят предметные характеристики конкретной роли. Для этого используются отдельные профили участия, описанные в [Identity Participation Profiles](identity-participation-profiles.md).

Например, роль `player` фиксирует факт участия пользователя как игрока, а `PlayerProfile` хранит рост, вес, позицию, стаж, комментарий и расширяемые данные игрока.

## Связь и ограничения

- `User` -> `hasMany(UserParticipationRole)`
- `UserParticipationRole` -> `belongsTo(User)`
- `UserParticipationRole.assignedByUser()` -> `belongsTo(User, assigned_by)`
- `UserParticipationRole.assigned_by` хранит `users.id`, если роль назначена пользователем
- действует ограничение `unique(user_id, role)` для глобального набора ролей участия без контекстных оверрайдов
