# 011 - Добавить Makefile-команду назначения системной роли пользователя

## Оригинальное описание

Пользователь попросил добавить временную dev/VDS Makefile-команду:

> make sr superadmin {login}, make sr admin {login}

Суть: установить системную роль пользователя по его логину.

## Подробное описание

Нужно добавить удобную команду для текущего этапа разработки, когда проект еще не запущен в реальное использование, но админские роли нужно быстро выдавать на локальном окружении и VDS.

Требования:

- команда должна работать через существующий Makefile-интерфейс `ENV=dev|prod`;
- основной UX: `make sr superadmin login` и `make sr admin login`;
- команда должна валидировать роль через `UserSystemRoleEnum`;
- пользователь ищется по `users.username`;
- если пользователь не найден или роль невалидна, команда должна завершаться ошибкой;
- реализация не должна обходить доменную модель прямым SQL из Makefile.

## Затронутые файлы

- `Makefile`;
- `app/Console/Commands`;
- `docs/specification/identity-roles.md`;
- `docs/specification/docker-environment.md`;
- `docs/tasks.md`.

## Проверки

- `php artisan list`;
- dry-run Makefile-команд для dev/prod;
- feature/console test назначения роли.

## Результат

Добавлена artisan-команда:

```bash
php artisan identity:set-system-role {role} {username}
```

Добавлена Makefile-обертка:

```bash
make sr superadmin user_login
make sr admin user_login
make ENV=prod sr superadmin user_login
```

Команда ищет пользователя по `users.username`, валидирует роль через `UserSystemRoleEnum` и обновляет `users.system_role`.

Проверки:

- `php artisan list` - команда зарегистрирована;
- `make sr superadmin user_login -n` - пройден;
- `make ENV=prod sr admin user_login -n` - пройден;
- `php artisan test tests/Feature/Identity/SetUserSystemRoleCommandTest.php` - пройден.
