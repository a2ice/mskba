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

Docker Compose разделен на базовый файл и environment overrides:

```text
compose.yaml
compose.override.yaml
compose.prod.yaml
```

`compose.yaml` содержит общие сервисы runtime.

`compose.override.yaml` содержит local/dev настройки и используется с базовым файлом для локальной разработки.

`compose.prod.yaml` содержит VDS/prod настройки и используется с базовым файлом для публичного dev/staging runtime на VDS.

Docker project names:

```text
dev: mskbabrandnew
prod/VDS: mskbanew
```

## Сервисы

Базовый `compose.yaml` содержит общие runtime-сервисы:

- `phpfpm` - PHP-FPM runtime приложения;
- `nginx` - контейнерный Nginx, слушает `${NGINX_PORT:-8000}:80`;
- `queue` - Laravel queue worker;
- `scheduler` - постоянно работающий Laravel scheduler;
- `db` - PostgreSQL 17 Alpine, база `mskbabrandnew`, пользователь `mskbabrandnew`;
- `redis` - Redis 7 для runtime/cache/queue сценариев.

Dev override `compose.override.yaml` добавляет:

- container names `mskbabrandnew-*`;
- `MAIL_HOST=mailpit` для `phpfpm` и `queue`;
- публичные dev-порты для `db` и `redis`;
- `adminer` - web-интерфейс для PostgreSQL;
- `mailpit` - локальный SMTP/Web UI для просмотра исходящей почты.

Prod override `compose.prod.yaml` добавляет:

- container names `mskbanew-*`;
- bind DB port только на `${DB_FORWARD_PORT:-127.0.0.1:5433}`;
- build-only сервис `node` с profile `build`.

Текущие volumes:

- `postgres_data` - данные PostgreSQL.
- `redis_data` - данные Redis.
- `node_modules` - node dependencies для production build profile.

Mailpit:

- SMTP: `127.0.0.1:1025`;
- Web UI: `http://localhost:8025`.

## Production runtime

Production/VDS runtime собирается из `compose.yaml` и `compose.prod.yaml`, чтобы общие сервисы не дублировались между окружениями.

Production compose project name временно оставлен `mskbanew`. Старую версию проекта можно полностью удалять вместе с БД, контейнерами, volume и другими артефактами, если они мешают новой production-схеме.

Production/VDS services:

- `phpfpm` - PHP-FPM runtime приложения;
- `nginx` - контейнерный Nginx, слушает `${NGINX_PORT:-8000}:80`;
- `queue` - Laravel queue worker;
- `scheduler` - Laravel scheduler, запускающий due-задачи каждую минуту;
- `telegram` - long polling Telegram updates;
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

Workflow подключается к VDS по SSH, работает в `/var/www/mskba`, обновляет код из `origin/main`, собирает PHP image, устанавливает Composer-зависимости, собирает Vite assets через Node container, запускает миграции, очищает Laravel caches и кеширует config до подъема `nginx`. После обновления workflow пересоздаёт и перезапускает `phpfpm`, `nginx`, `queue`, `scheduler` и `telegram`.

Deploy workflow использует:

```bash
docker compose -f compose.yaml -f compose.prod.yaml
```

Workflow не использует `sudo`. Права на `storage` и `bootstrap/cache` выставляются через PHP container как `deploy:www-data` с `ug+rwX`, чтобы и Git checkout, и PHP-FPM могли работать с этими директориями.

Перед изменением кода workflow выполняет preflight:

- останавливается, если на production `APP_DEBUG=true`;

Workflow не содержит отдельного guard для старой схемы проекта. Если старая production-БД или другие старые артефакты мешают деплою новой версии, их можно удалить и поднять целевую схему заново.

## Команды Makefile

Makefile использует единый интерфейс команд для local/dev и production compose.

По умолчанию команды работают с локальной комбинацией `compose.yaml` + `compose.override.yaml`:

```bash
make ps
make migrate
make artisan CMD='route:list'
```

Production/VDS-режим включается параметром `ENV=prod`. В этом режиме Makefile использует `compose.yaml` + `compose.prod.yaml`:

```bash
make ENV=prod ps
make ENV=prod migrate
make ENV=prod artisan CMD='route:list'
```

Для production-миграций `make ENV=prod migrate` автоматически добавляет Laravel-флаг `--force`.

Команды `fresh` и `fresh-seed` удаляют таблицы и данные. Сейчас VDS используется как публичный dev/staging runtime без production-пользователей, поэтому команды доступны с тем же интерфейсом, что и локально:

```bash
make ENV=prod fresh
make ENV=prod fresh-seed
```

Перед запуском проекта в реальное использование для destructive-команд нужно вернуть дополнительный production guard или выделить отдельное окружение `staging`.

Основные команды:

- `make up` - запустить compose-сервисы в detached-режиме;
- `make rebuild` - пересобрать и пересоздать compose-сервисы;
- `make down` - остановить compose-сервисы;
- `make restart` - перезапустить compose-сервисы;
- `make ps` - показать состояние compose-сервисов;
- `make config` - вывести итоговый Docker Compose config;
- `make migrate` - выполнить миграции;
- `make fresh` - пересоздать БД;
- `make fresh-seed` - пересоздать БД и выполнить сидеры;
- `make seed` - выполнить сидеры;
- `make cache-clear` - очистить Laravel caches;
- `make optimize-clear` - выполнить `artisan optimize:clear`;
- `make queue` - смотреть логи queue worker;
- `make queue-restart` - перезапустить queue worker;
- `make logs` - открыть Laravel Pail;
- `make shell` - открыть Tinker;
- `make artisan CMD='...'` - выполнить произвольную artisan-команду;
- `make npm CMD='...'` - выполнить произвольную npm-команду;
- `make sr <role> <login>` - назначить системную роль пользователю по логину;
- `make db-up` - запустить сервис `db`;
- `make db-down` - остановить compose-сервисы;
- `make db-restart` - перезапустить `db`;
- `make db-logs` - смотреть логи `db`.

## Что не реализовано

В текущей Docker-схеме нет:

- отдельного `staging` override;
- отдельного guard для destructive-команд на будущем production с реальными пользователями.

Отдельные команды вида `make prod-up` не используются: production/VDS выбирается через `ENV=prod`, чтобы dev и VDS имели одинаковые имена команд.

## Будущее развитие

Следующие вопросы остаются отдельными задачами:

- импорт данных из старой production-БД, если он понадобится;
- проверка host-level Nginx/HTTPS на VDS;
- переименование production compose project/container names с `mskbanew` на `mskba`;
- выделение отдельного `staging` окружения, если VDS runtime нужно будет отделить от будущего production.
