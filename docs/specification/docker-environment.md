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
- `adminer` - web-интерфейс для работы с PostgreSQL;
- `mailpit` - локальный SMTP/Web UI для просмотра исходящей почты.

Текущий volume:

- `postgres-data` - данные PostgreSQL.

Mailpit:

- SMTP: `127.0.0.1:1025`;
- Web UI: `http://localhost:8025`.

## Production runtime

Production runtime описан отдельно в `docker-compose.prod.yml`, чтобы не ломать локальный DB-only сценарий.

Production compose project name временно оставлен `mskbanew`. Старую версию проекта можно полностью удалять вместе с БД, контейнерами, volume и другими артефактами, если они мешают новой production-схеме.

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

- push в `main`, кроме изменений только в `docs/**` или `README.md`;
- ручной запуск через `workflow_dispatch`.

Workflow подключается к VDS по SSH, работает в `/var/www/mskba`, обновляет код из `origin/main`, собирает PHP image, устанавливает Composer-зависимости, собирает Vite assets через Node container, запускает миграции, очищает Laravel caches и кеширует config до подъема `nginx`.

Workflow не использует `sudo`. Права на `storage` и `bootstrap/cache` выставляются через PHP container как `deploy:www-data` с `ug+rwX`, чтобы и Git checkout, и PHP-FPM могли работать с этими директориями.

Перед изменением кода workflow выполняет preflight:

- останавливается, если на production `APP_DEBUG=true`;

Workflow не содержит отдельного guard для старой схемы проекта. Если старая production-БД или другие старые артефакты мешают деплою новой версии, их можно удалить и поднять целевую схему заново.

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
