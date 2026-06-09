# 010 - Унифицировать Makefile-команды для dev и production Docker Compose

## Оригинальное описание

Из `../backlog/todo.md`:

> B002 - Унифицировать Docker-окружение local/prod: вынести общие сервисы в базовый compose, dev-only сервисы в override, production-настройки в prod override, обновить deploy workflow и документацию

Уточнение пользователя:

> делаем пока все унифицированно с dev

## Подробное описание

Текущая проблема: на VDS команда `make fresh` запускает дефолтный `docker compose`, а production runtime был вынесен в отдельный `docker-compose.prod.yml`. Из-за этого Makefile-команды выглядели одинаково, но обращались к разным compose-проектам неявно и могли падать с ошибкой `service "phpfpm" is not running`.

В рамках этой задачи нужно сделать Docker Compose и Makefile единообразными для dev и VDS:

- сохранить привычные команды `make up`, `make migrate`, `make fresh`, `make cache-clear`, `make ps` и похожие;
- добавить переключение окружения через `ENV=prod`;
- по умолчанию оставить dev/local поведение;
- вынести общие сервисы в базовый `compose.yaml`;
- вынести local/dev настройки в `compose.override.yaml`;
- вынести VDS/prod настройки в `compose.prod.yaml`;
- обновить техническую документацию Docker/Makefile.

Текущий VDS используется как публичный dev/staging runtime до запуска проекта в использование, поэтому destructive-команды доступны с тем же интерфейсом, что и локально.

## Затронутые файлы

- `Makefile`;
- `compose.yaml`;
- `compose.override.yaml`;
- `compose.prod.yaml`;
- `.github/workflows/deploy.yml`;
- `docs/specification/docker-environment.md`;
- `docs/tasks.md`;
- `../backlog/todo.md`.

## Проверки

- `make help`;
- `make ENV=prod ps` или dry-run/конфигурационная проверка production compose;
- проверка, что dev-команды остаются совместимыми с текущим Makefile-интерфейсом.

## Результат

Docker Compose переведен на схему base + overrides:

- `compose.yaml` - общие runtime-сервисы `phpfpm`, `nginx`, `queue`, `db`, `redis`;
- `compose.override.yaml` - local/dev настройки, `adminer`, `mailpit`, dev-порты и dev container names;
- `compose.prod.yaml` - VDS/prod настройки, prod container names, закрытый наружу Redis, DB bind на `127.0.0.1:5433`, build-only `node`.

Старые файлы `docker-compose.yml` и `docker-compose.prod.yml` удалены.

Makefile получил переключатель `ENV=dev|prod` с `dev` по умолчанию.

Одинаковые команды теперь работают с разными compose-файлами:

- `make migrate` -> `docker compose -f compose.yaml -f compose.override.yaml exec phpfpm php artisan migrate`;
- `make ENV=prod migrate` -> `docker compose -f compose.yaml -f compose.prod.yaml exec phpfpm php artisan migrate --force`;
- `make artisan CMD='route:list'` -> dev compose;
- `make ENV=prod artisan CMD='route:list'` -> production compose.

Команды `fresh` и `fresh-seed` в `ENV=prod` доступны без дополнительного guard, потому что текущий VDS используется как публичный dev/staging runtime до запуска проекта в использование.

Deploy workflow обновлен на `docker compose -f compose.yaml -f compose.prod.yaml`.
Asset build в deploy workflow переведен на build-only сервис `node` из `compose.prod.yaml`.

Документация `docs/specification/docker-environment.md` обновлена: описаны compose base/overrides, `ENV=prod`, единый Makefile-интерфейс и актуальный состав Docker runtime.

Проверки:

- `make help` - пройден;
- `make config` - пройден;
- `make ENV=prod config` - пройден;
- `docker compose -f compose.yaml -f compose.prod.yaml --profile build config --services` - пройден;
- shell syntax deploy script из `.github/workflows/deploy.yml` через `bash -n` - пройден;
- `make migrate -n` - пройден;
- `make ENV=prod migrate -n` - пройден;
- `make artisan CMD='route:list' -n` - пройден;
- `make ENV=prod artisan CMD='route:list' -n` - пройден;
- `make ENV=prod build -n` - пройден;
- `make ENV=prod npm CMD='ci' -n` - пройден;
- `make ENV=prod fresh -n` - пройден.
- `make ENV=prod fresh-seed -n` - пройден.
