# Docker Environment

Технический документ про текущую Docker-конфигурацию проекта и связанные команды разработки.

## Оглавление

- [Назначение](#назначение)
- [Текущее состояние](#текущее-состояние)
- [Сервисы](#сервисы)
- [Production runtime](#production-runtime)
- [GitHub Actions deploy](#github-actions-deploy)
- [Команды Makefile](#команды-makefile)
- [Что не реализовано](#что-не-реализовано)
- [Будущее развитие](#будущее-развитие)

## Назначение

Документ фиксирует только фактическое состояние Docker-окружения в текущей версии проекта. Он не описывает целевой production-стек как уже реализованный.

## Текущее состояние

Для локальной разработки в проекте есть основной Docker Compose:

```text
docker-compose.yml
```

Docker project name:

```text
mskbabrandnew
```

Файл `docker-compose.dev.yml` в текущей версии отсутствует.

Для production-деплоя добавлен отдельный compose-файл:

```text
docker-compose.prod.yml
```

## Сервисы

Локальный `docker-compose.yml` содержит:

- `postgres` - PostgreSQL 17 Alpine, база `mskbabrandnew`, пользователь `mskbabrandnew`;
- `adminer` - web-интерфейс для работы с PostgreSQL.

Текущий volume:

- `postgres-data` - данные PostgreSQL.

## Production runtime

Production runtime описан отдельно в `docker-compose.prod.yml`, чтобы не ломать локальный DB-only сценарий.

Production compose project name временно оставлен `mskbanew`. Это сделано для совместимости с текущей VDS-конфигурацией, где уже существуют контейнеры и Docker labels старого проекта.

Production services:

- `phpfpm` - PHP-FPM runtime приложения;
- `nginx` - контейнерный Nginx, слушает `${NGINX_PORT:-8000}:80`;
- `db` - PostgreSQL 17, использует новый volume `mskbabrandnew_postgres_data`;
- `redis` - Redis 7 для будущего runtime/cache/queue сценария;
- `node` - build-only сервис с profile `build` для `npm ci && npm run build`.

Production Dockerfile:

```text
docker/app/Dockerfile
```

Nginx-конфигурация:

```text
docker/nginx/default.conf
```

Порты `db` и `redis` не должны быть публичными в production. В текущем production compose `db` по умолчанию пробрасывается только на `127.0.0.1:5433`, а `redis` наружу не пробрасывается.

## GitHub Actions deploy

Workflow:

```text
.github/workflows/deploy.yml
```

Триггеры:

- push в `main`;
- ручной запуск через `workflow_dispatch`.

Workflow подключается к VDS по SSH, работает в `/var/www/mskba`, обновляет код из `origin/main`, собирает PHP image, устанавливает Composer-зависимости, собирает Vite assets через Node container, делает SQL backup в `~/mskba-db-backups` перед миграциями, запускает миграции и кеширует Laravel config до подъема `nginx`.

Workflow не использует `sudo`. Права на `storage` и `bootstrap/cache` выставляются через PHP container.

Перед изменением кода workflow выполняет preflight:

- останавливается, если на production `APP_DEBUG=true`;
- останавливается, если `.env` указывает на старую базу `DB_DATABASE=mskba`, найдены признаки legacy schema старого проекта (`contacts`, `contact_verifications`, `user_profiles`) и явно не задано `ALLOW_LEGACY_DB_DEPLOY=1`.

Такой guard нужен потому, что текущая VDS-БД старого проекта не совпадает с миграциями новой кодовой базы. Целевой путь для первого deploy новой версии - новая база `mskbabrandnew` на отдельном volume `mskbabrandnew_postgres_data`.

## Команды Makefile

С Docker связаны следующие команды:

- `make up` - запустить compose-сервисы в detached-режиме;
- `make down` - остановить compose-сервисы;
- `make restart` - перезапустить compose-сервисы;
- `make db-up` - запустить сервис `postgres`;
- `make db-down` - остановить compose-сервисы;
- `make db-restart` - перезапустить `postgres`;
- `make db-logs` - смотреть логи `postgres`.

Общие команды проекта, не завязанные на Docker runtime:

- `make install`
- `make update`
- `make dev`
- `make serve`
- `make vite`
- `make build`
- `make test`
- `make lint`
- `make format`
- `make migrate`
- `make fresh`
- `make fresh-seed`
- `make seed`
- `make cache-clear`
- `make optimize-clear`
- `make queue`
- `make logs`
- `make shell`
- `make module`
- `make delete-module`

## Что не реализовано

В текущей Docker-схеме нет:

- `mailpit`;
- отдельного dev override;
- Makefile-команд для production compose.

Команды `make prod-up`, `make prod-down`, `make config`, `make dev-config`, `make npm` и `make artisan` отсутствуют.

## Будущее развитие

Следующие вопросы остаются отдельными задачами:

- импорт данных из старой production-БД, если он понадобится;
- проверка host-level Nginx/HTTPS на VDS;
- переименование production compose project/container names с `mskbanew` на `mskba`;
- добавление dev override, если понадобится полноценный Docker dev runtime.
