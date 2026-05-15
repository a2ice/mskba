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
  - `assigned_by_user_id`;
  - `assigner`;
  - `comment`;
- для `status` должен использоваться enum, а не boolean;
- для источника назначения должны использоваться два поля:
  - nullable `assigned_by_user_id`;
  - enum `assigner`, описывающий источник назначения, например пользователь, миграция и другие варианты.

Детальная декомпозиция, состав enum-значений, миграции, модельные связи, документационные изменения и проверки должны быть оформлены на этапе планирования задачи.

