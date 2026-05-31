# Docker Environment

Технический документ про каноническую Docker-схему проекта, различия между dev и prod и рабочие команды для локальной разработки и деплоя.

## Оглавление

- [Назначение](#назначение)
- [Базовая схема окружения](#базовая-схема-окружения)
- [Dev-надстройка](#dev-надстройка)
- [Именование](#именование)
- [Команды и режимы](#команды-и-режимы)
- [Frontend Assets](#frontend-assets)
- [Production-подход](#production-подход)

## Назначение

Документ фиксирует каноническую Docker-конфигурацию проекта. Цель схемы: local и prod должны быть максимально похожи по базовым сервисам, а dev-only инструменты должны подключаться отдельно и не попадать в production по умолчанию.

## Базовая схема окружения

Базовый `docker-compose.yml` общий для dev и prod и должен содержать только сервисы, реально необходимые приложению:

- `phpfpm`
- `nginx`
- `db`
- `redis`

Назначение сервисов:

- `phpfpm` - PHP-FPM контейнер приложения Laravel.
- `nginx` - HTTP entrypoint и FastCGI proxy к `phpfpm`.
- `db` - PostgreSQL.
- `redis` - инфраструктурный сервис под будущие cache/queue/use-case сценарии.

Базовая схема должна подниматься и локально, и на production без dev-инструментов.

## Dev-надстройка

Локальные инструменты разработки должны подключаться через `docker-compose.dev.yml`:

- `mailpit`
- `adminer`
- `node`

Правила:

- dev-only сервисы не должны попадать в production-сценарий;
- различия между dev и prod задаются override-файлом, а не двумя независимыми compose-схемами;
- если сервис нужен приложению в любой среде, он должен жить в базовом `docker-compose.yml`, а не только в dev override.

## Именование

- Docker project name: `mskbanew`
- PHP-FPM сервис называется `phpfpm`, а не `app`
- upstream в nginx должен ссылаться на `phpfpm:9000`

Имена сервисов должны отражать их реальную роль, а не быть общими или двусмысленными.

## Команды и режимы

Основные команды описаны в `Makefile`.

Базовые принципы:

- `make up` - базовая схема в foreground;
- `make dev` - базовая схема плюс dev-only сервисы;
- `make prod-up` - базовая схема в detached-режиме;
- `make down` - остановка dev-схемы;
- `make prod-down` - остановка базовой схемы;
- `make config` - проверка базового compose;
- `make dev-config` - проверка dev override.

Перед изменениями Docker-схемы полезно проверять:

- `docker compose config`
- `docker compose -f docker-compose.yml -f docker-compose.dev.yml config`

## Frontend Assets

Текущий production deploy не собирает frontend-ассеты на VDS. На production не запускаются `npm ci` и `npm run build`, а `node` остается dev-only сервисом.

Из этого следуют правила:

- если изменения затрагивают frontend-исходники (`js`, `css`, Vite entrypoints, frontend-компоненты), перед push нужно выполнить локальную сборку ассетов;
- вместе с изменениями исходников нужно закоммитить актуальные build-артефакты из `public/build`;
- backend-изменения, не затрагивающие frontend-ассеты, не требуют обязательной локальной asset-сборки;
- если забыть локальную сборку и запушить только исходники, deploy может пройти успешно, но production останется со старым или неконсистентным frontend build.

Рекомендуемые команды:

- `make npm CMD='run build'`
- `npm run build`

## Production-подход

Production должен использовать базовый `docker-compose.yml` без dev-only override.

GitHub Actions и deploy-автоматизация должны:

- работать с теми же canonical service names;
- поднимать только базовые production-сервисы;
- не опираться на старые имена вроде `app`, если сервис фактически является `phpfpm`.

Если production можно пересобрать с нуля и нет требований обратной совместимости со старой Docker-схемой, предпочтение отдается чистой целевой конфигурации, а не переходным адаптерам.

Если на production уже есть живая база данных в существующем Docker volume, смена Docker project name не должна неявно создавать новый пустой volume для PostgreSQL. В таком случае volume базы должен быть явно привязан к существующему production volume либо миграция данных должна быть выполнена отдельно и осознанно.
