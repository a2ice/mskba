# 002 - Рефакторинг системных и пользовательских ролей

## Оригинальное описание

Рефакторинг системных и пользовательских ролей: `UserRole` -> `UserSystemRole`, nullable `system_role`, модель `UserParticipationRole`.

## Подробное описание

На этапе постановки задачи согласованы следующие решения:

- текущий `UserRoleEnum` должен быть переименован в `UserSystemRoleEnum`;
- поле `role` у `User` должно быть переименовано в `system_role` и стать nullable;
- case `GUEST` должен быть удален;
- пользовательские роли участия должны быть вынесены в отдельную модель `UserParticipationRole`, а не в pivot;
- для ролей участия должен быть заведен enum `UserParticipationRoleEnum`;
- стартовые participation roles: `player`, `coach`, `referee`, `statistician`, `media`, `venue_related`;
- должна быть отдельная таблица `user_participation_roles`;
- должен быть уникальный индекс `unique(user_id, role)`;
- модель роли участия должна поддерживать как минимум поля:
  - `user_id`;
  - `role`;
  - `status`;
  - `assigned_at`;
  - `expires_at`;
  - `assigned_by`;
  - `assigner`;
  - `comment`;
- для `status` должен использоваться enum, а не boolean;
- для источника назначения должны использоваться два поля:
  - nullable `assigned_by`;
  - enum `assigner`, описывающий источник назначения, например пользователь, миграция и другие варианты.

На этапе планирования дополнительно зафиксировано:

- текущие использования `UserRoleEnum` локальны и затрагивают:
  - enum `UserRoleEnum`;
  - модель `User`;
  - миграцию `create_users_table`;
  - factory `UserFactory`;
- скрытых зависимостей по полю `role` вне identity-модуля сейчас не найдено;
- рефакторинг может быть выполнен без декомпозиции на подзадачи, так как изменение остается в пределах одного смыслового блока identity/roles.

План реализации:

1. Переименовать `UserRoleEnum` в `UserSystemRoleEnum`.
2. В целевой схеме использовать поле `users.system_role` как nullable и убрать `GUEST`.
3. Обновить модель `User`, casts, fillable и все текущие использования нового имени поля и enum.
4. Добавить новую доменную модель `UserParticipationRole`.
5. Добавить enum `UserParticipationRoleEnum` со значениями:
   - `player`
   - `coach`
   - `referee`
   - `statistician`
   - `media`
   - `venue_related`
6. Добавить enum статуса роли участия. Рабочее имя на текущем этапе: `UserParticipationRoleStatusEnum` со значениями `active` и `inactive`.
7. Добавить enum источника назначения `UserParticipationRoleAssignerEnum` с согласованным стартовым набором значений:
   - `user`
   - `flow`
   - `seeder`
   - `other`
   Значения enum должны быть снабжены короткими комментариями, поясняющими смысл каждого источника, особенно `flow` и `other`.
8. Добавить таблицу `user_participation_roles` и связать ее с `User` через `hasMany`.

Состав новой таблицы `user_participation_roles`:

- `id`
- `user_id`
- `role`
- `status`
- `assigned_at`
- `expires_at`
- `assigned_by`
- `assigner`
- `comment`
- `created_at`
- `updated_at`

Ограничения и правила данных:

- `unique(user_id, role)` на первом этапе глобально;
- `assigned_at` должен устанавливаться текущим временем по умолчанию;
- `expires_at = null` означает бессрочную роль;
- `assigned_by = null` допустим для системных и технических источников назначения;
- `assigner` обязателен, чтобы источник назначения был явным даже при `assigned_by = null`.
- на текущем этапе `assigned_by` хранит `user.id`, если источник назначения связан с пользователем, но имя поля оставляет пространство для будущего расширения.

Документационные изменения:

- обновить `docs/project.md` в части ролей пользователей;
- при необходимости добавить или обновить раскрытие продуктовой темы ролей;
- обновить `docs/specification.md` или раскрытия, если понадобится зафиксировать новую модель ролей;
- зафиксировать результат выполнения в этом файле задачи.

Проверки:

- релевантные feature/unit тесты по identity, если затрагиваются текущие сценарии;
- миграции должны быть валидны;
- при необходимости `php artisan test`;
- при необходимости `php artisan migrate:fresh --seed` не обязателен, если достаточно локального test run и проверки схемы.

Принятое уточнение в ходе реализации:

- переходная рефакторинговая миграция для переноса `users.role` в `users.system_role` не нужна, пока в проекте нет реальных данных;
- в таких случаях нужно предпочитать сразу корректную целевую схему вместо временного слоя миграций переноса данных;
- подобные варианты нужно явно уточнять до реализации, если задача затрагивает структуру данных.

## Результат выполнения

Выполнен рефакторинг ролей в identity-слое:

- `UserRoleEnum` заменен на `UserSystemRoleEnum`;
- в целевой схеме используется nullable поле `users.system_role`;
- case `GUEST` удален;
- добавлена модель `UserParticipationRole`;
- добавлены enum:
  - `UserParticipationRoleEnum`
  - `UserParticipationRoleStatusEnum`
  - `UserParticipationRoleAssignerEnum`
- добавлена таблица `user_participation_roles`;
- в `User` добавлены связи:
  - `participationRoles()`
  - `assignedParticipationRoles()`
- в `UserParticipationRole` добавлены связи:
  - `user()`
  - `assignedByUser()`

Документация обновлена:

- `docs/project.md`
- `docs/project/roli-polzovatelei.md`
- `docs/specification.md`
- `docs/specification/identity-roles.md`

## Проверки выполнения

```bash
php artisan test tests/Feature/AuthClassicFlowTest.php tests/Feature/UserParticipationRolesTest.php
php artisan test
```

Результат:

- 5 identity-oriented тестов пройдены;
- полный test run пройден;
- всего 7 тестов, 34 assertion.
