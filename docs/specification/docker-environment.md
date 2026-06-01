# Docker Environment

Технический документ про текущую Docker-конфигурацию проекта и связанные команды разработки.

## Оглавление

- [Назначение](#назначение)
- [Текущее состояние](#текущее-состояние)
- [Сервисы](#сервисы)
- [Команды Makefile](#команды-makefile)
- [Что не реализовано](#что-не-реализовано)
- [Будущее развитие](#будущее-развитие)

## Назначение

Документ фиксирует только фактическое состояние Docker-окружения в текущей версии проекта. Он не описывает целевой production-стек как уже реализованный.

## Текущее состояние

В проекте есть один файл Docker Compose:

```text
docker-compose.yml
```

Docker project name:

```text
mskbabrandnew
```

Файл `docker-compose.dev.yml` в текущей версии отсутствует.

## Сервисы

Текущий `docker-compose.yml` содержит:

- `postgres` - PostgreSQL 17 Alpine, база `mskbabrandnew`, пользователь `mskbabrandnew`;
- `adminer` - web-интерфейс для работы с PostgreSQL.

Текущий volume:

- `postgres-data` - данные PostgreSQL.

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

- `phpfpm`;
- `nginx`;
- `redis`;
- `mailpit`;
- `node`;
- отдельного dev override;
- отдельного production compose-файла.

Команды `make prod-up`, `make prod-down`, `make config`, `make dev-config`, `make npm` и `make artisan` отсутствуют.

## Будущее развитие

Если проекту нужен полноценный Docker runtime для Laravel, его нужно проектировать отдельной задачей. В такой задаче можно будет вернуться к сервисам `phpfpm`, `nginx`, `redis`, dev-only сервисам и production-сценарию.

До появления такой задачи документация не должна описывать эти сервисы как уже существующие.
