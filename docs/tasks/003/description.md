# 003 - Настроить GitHub Actions автодеплой текущего проекта на VDS

## Оригинальное описание

Настроить GitHub Actions автодеплой текущего проекта `mskbabrandnew/site` на VDS через GitHub-репозиторий `git@github-a2ice:a2ice/mskba.git`.

Перед реализацией нужно предварительно изучить текущую конфигурацию проекта и старую конфигурацию проекта `mskbanew/site`. Если для безопасной настройки нужен доступ к VDS, запросить его отдельно.

## Предварительный аудит конфигурации

### GitHub

Текущий проект уже подключен к старому GitHub-репозиторию:

```text
origin git@github-a2ice:a2ice/mskba.git
```

Ветка `main` нового проекта уже опубликована в `origin/main`.

В текущем проекте нет директории `.github`, поэтому автодеплой сейчас не запускается.

### Старый deploy

В старом проекте `mskbanew/site` был workflow:

```text
.github/workflows/deploy.yml
```

Он запускался при push в `main`, подключался к VDS по SSH и выполнял команды в:

```text
/var/www/mskba
```

Старый workflow ожидал production Docker stack с сервисами:

- `phpfpm`;
- `db`;
- `redis`;
- `nginx`.

Также он выполнял:

- `git fetch origin main`;
- `git reset --hard origin/main`;
- `docker compose up -d phpfpm db redis nginx`;
- Laravel-команды внутри контейнера `phpfpm`.

### Старый Docker stack

В старом проекте были:

- `docker-compose.yml`;
- `docker-compose.dev.yml`;
- `docker/app/Dockerfile`;
- `docker/nginx/default.conf`.

Production-like compose включал:

- `phpfpm` на базе `php:8.4-fpm-bookworm`;
- `nginx:1.27-alpine`;
- `postgres:17-alpine`;
- `redis:7-alpine`.

Nginx смотрел в `/var/www/html/public` и передавал PHP-запросы в `phpfpm:9000`.

### Текущий Docker stack

В текущем проекте есть только:

```text
docker-compose.yml
```

Сервисы:

- `postgres`;
- `adminer`.

В текущей Docker-схеме нет:

- `phpfpm`;
- `nginx`;
- `redis`;
- `mailpit`;
- `node`;
- `docker/app/Dockerfile`;
- `docker/nginx/default.conf`;
- отдельного production compose-файла.

Техническая документация сейчас корректно фиксирует, что полноценный Docker runtime не реализован.

### Важное отличие по frontend assets

В текущем проекте `public/build` игнорируется в `.gitignore`.

Значит production deploy должен либо:

- собирать assets на сервере;
- либо собирать assets в GitHub Actions и доставлять результат на VDS;
- либо поменять стратегию хранения build-артефактов отдельным осознанным решением.

Старый workflow не выполнял `npm ci` и `npm run build`, поэтому в текущем проекте его нельзя вернуть без доработки.

## Риск прямого переноса старого workflow

Старый workflow нельзя просто скопировать в текущий проект, потому что:

- он ожидает сервисы `phpfpm`, `db`, `redis`, `nginx`, которых нет в текущем `docker-compose.yml`;
- в текущем compose сервис базы называется `postgres`, а не `db`;
- текущий deploy должен учитывать сборку Vite assets;
- неизвестно, в каком состоянии сейчас `/var/www/mskba` на VDS после замены `origin/main`;
- неизвестно, какие значения `.env` и Docker volumes используются на VDS;
- нужно проверить, не завязаны ли внешние Nginx/HTTPS-настройки сервера на старые container names или порты.

## Целевое решение

Нужно настроить production deploy как отдельный инфраструктурный слой текущего проекта, а не восстанавливать старую схему без проверки.

Целевой минимум:

1. Добавить production Docker runtime для Laravel:
   - `phpfpm`;
   - `nginx`;
   - `postgres`;
   - при необходимости `redis`.
2. Добавить Dockerfile для PHP с нужными расширениями:
   - `pdo_pgsql`;
   - `intl`;
   - `zip`;
   - `opcache`;
   - `redis`, если Redis остается в production stack.
3. Добавить Nginx-конфигурацию для Laravel `public`.
4. Определить стратегию frontend build:
   - предпочтительно собрать assets в deploy pipeline до перезапуска приложения;
   - не коммитить `public/build`, если стратегия остается текущей.
5. Создать `.github/workflows/deploy.yml` под текущую схему.
6. Проверить GitHub Actions secrets:
   - `SERVER_HOST`;
   - `SERVER_SSH_KEY`;
   - при необходимости `SERVER_USER`, если пользователь не `deploy`.
7. Проверить VDS перед включением workflow.

## Что нужно проверить на VDS

Для безопасной настройки нужен доступ к VDS или вывод следующих команд с сервера.

Рабочая директория и git:

```bash
pwd
ls -la /var/www/mskba
cd /var/www/mskba
git remote -v
git status --short --branch
git rev-parse HEAD
```

Docker:

```bash
docker --version
docker compose version
cd /var/www/mskba
docker compose ps
docker compose config --services
docker volume ls | grep mskba
```

Текущие контейнеры и порты:

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Ports}}'
```

Файлы окружения без вывода секретов:

```bash
cd /var/www/mskba
test -f .env && grep -E '^(APP_ENV|APP_DEBUG|APP_URL|APP_THEME|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|REDIS_HOST|REDIS_PORT)=' .env
```

Права на директории:

```bash
cd /var/www/mskba
ls -ld storage bootstrap/cache public public/build 2>/dev/null || true
```

Внешний Nginx/HTTPS, если он установлен вне Docker:

```bash
sudo nginx -T 2>/dev/null | grep -nE 'server_name|proxy_pass|/var/www/mskba|127.0.0.1|8000|443|80'
```

## Фактическая проверка VDS

Проверка выполнена 2026-06-01 через SSH под пользователем `deploy`.

### Система и рабочая директория

- OS: Ubuntu 22.04.5 LTS.
- Рабочая директория проекта: `/var/www/mskba`.
- Git remote: `git@github-a2ice:a2ice/mskba.git`.
- Сервер видит новый `origin/main`: `eaf73cf`.
- Рабочая директория сервера пока на старом коммите: `dd9107e`.
- `git status` показывает старый рабочий tree и untracked build asset в `public/build`.

Это означает, что после замены GitHub `main` новый код еще не применялся на сервере.

### Docker на VDS

- Docker: `29.4.3`.
- Docker Compose: `v5.1.3`.

Текущий compose на сервере все еще старый и содержит сервисы:

- `db`;
- `redis`;
- `phpfpm`;
- `nginx`.

Запущенные контейнеры:

- `mskbanew-db`;
- `mskbanew-redis`;
- `mskbanew-phpfpm`;
- `mskbanew-nginx`.

Docker Nginx слушает порт `8000` и проксирует Laravel из `/var/www/html/public` в `phpfpm:9000`.

### Сетевые порты

На VDS слушают:

- `80`;
- `443`;
- `8000`;
- `5433`;
- `6379`.

Порт `8000` используется Docker Nginx. Вероятно, внешний Nginx/HTTPS на host-системе проксирует трафик с `80/443` на `8000`, но это нужно подтвердить через host Nginx config.

Порты `5433` и `6379` сейчас проброшены наружу. Это нужно отдельно проверить с точки зрения безопасности production-сервера.

### Env без секретов

Серверный `.env` сейчас содержит:

- `APP_ENV=production`;
- `APP_DEBUG=true`;
- `APP_URL=https://mskba.ru`;
- `DB_CONNECTION=pgsql`;
- `DB_HOST=db`;
- `DB_PORT=5432`;
- `DB_DATABASE=mskba`;
- `DB_USERNAME=dev`;
- `SESSION_DRIVER=database`;
- `QUEUE_CONNECTION=database`;
- `CACHE_STORE=database`;
- `REDIS_HOST=redis`;
- `REDIS_PORT=6379`.

`APP_DEBUG=true` на production было найдено при аудите и исправлено на `APP_DEBUG=false` 2026-06-01. Перед правкой на сервере создан backup `.env`, затем backup перенесен в `~/mskba-env-backups`, чтобы будущий `git clean` в checkout его не удалил.

### База данных на VDS

В production-БД старого проекта обнаружены таблицы:

- `cache`;
- `cache_locks`;
- `contact_verifications`;
- `contacts`;
- `failed_jobs`;
- `job_batches`;
- `jobs`;
- `migrations`;
- `password_reset_tokens`;
- `sessions`;
- `user_participation_roles`;
- `user_profiles`;
- `users`;
- `venues`.

В таблице `migrations` есть записи старого проекта:

- `0001_01_01_000003_create_contacts_table`;
- `0001_01_01_000004_create_contact_verifications_table`;
- `2026_05_15_153000_create_user_profiles_table`;
- `2026_05_21_000001_create_venues_table`.

Новая кодовая база ожидает другую схему:

- `profiles` вместо `user_profiles`;
- новые contract tables;
- другую миграцию площадок.

Поэтому первый production deploy нельзя считать чисто техническим переключением. Нужно отдельно принять решение по БД: миграция данных, новая база или осознанный сброс старых данных.

### Что не удалось проверить без sudo

Команда `sudo nginx -T` требует пароль sudo. Без нее не видно точную конфигурацию host-level Nginx/HTTPS и нельзя подтвердить, как именно `mskba.ru` проксируется на Docker.

Перед первым production deploy желательно проверить:

- host Nginx server block для `mskba.ru`;
- TLS/Let's Encrypt конфигурацию;
- upstream/proxy target;
- можно ли оставить Docker Nginx на порту `8000` без изменения внешней схемы.

## План выполнения

1. Получить или проверить данные VDS из секции выше.
2. Принять решение по production Docker naming:
   - оставить старые имена сервисов `phpfpm`, `db`, `redis`, `nginx` для совместимости со старым workflow;
   - или привести текущий compose к новым именам и обновить workflow под них.
3. Спроектировать production compose так, чтобы он не ломал локальный DB-only сценарий разработки.
4. Добавить Dockerfile и Nginx-конфиг.
5. Добавить GitHub Actions workflow.
6. Продумать сборку frontend assets:
   - `npm ci`;
   - `npm run build`;
   - доставка `public/build` на сервер или сборка внутри серверного checkout.
7. Проверить локально:
   - `docker compose config`;
   - `npm run build`;
   - `php artisan route:list`;
   - `git diff --check`.
8. После подтверждения пользователя выполнить первый ручной deploy или push-trigger deploy.
9. По итогам обновить `docs/specification/docker-environment.md` и, если нужно, `README.md`.

## Выполнение

Добавлены файлы production runtime:

- `.dockerignore`;
- `docker-compose.prod.yml`;
- `docker/app/Dockerfile`;
- `docker/nginx/default.conf`;
- `.github/workflows/deploy.yml`.

### Production compose

`docker-compose.prod.yml` оставляет project name `mskbanew`.

Причина: на VDS уже существуют контейнеры старого compose с project labels `mskbanew`. Сохранение project name и service names `phpfpm`, `nginx`, `db`, `redis` позволяет Docker Compose управлять существующими контейнерами при первом переходе, а не создавать конфликтующие контейнеры на тех же портах.

Переименование production compose project/container names на `mskba` нужно выполнять отдельной задачей после стабильного deploy.

Production services:

- `phpfpm`;
- `nginx`;
- `db`;
- `redis`;
- `node` с profile `build`.

Важное отличие от старого compose:

- `db` использует новую production-БД `mskbabrandnew`;
- `db` использует новый volume `mskbabrandnew_postgres_data`;
- старый volume `mskba_postgres_data` остается нетронутым как архив старой БД;
- `db` по умолчанию пробрасывается только на `127.0.0.1:5433`;
- `redis` наружу не пробрасывается;
- `node` используется для server-side сборки `public/build`.

### Workflow

Workflow запускается:

- при push в `main`;
- вручную через `workflow_dispatch`.

Deploy script:

1. Проверяет наличие `.env` на VDS.
2. Останавливается, если `APP_DEBUG=true`.
3. До `git reset` проверяет legacy DB schema старого проекта только если `.env` указывает на старую базу `DB_DATABASE=mskba`.
4. Останавливается, если для старой базы найдены `contacts`, `contact_verifications` или `user_profiles`, кроме случая явного override `ALLOW_LEGACY_DB_DEPLOY=1`.
5. Обновляет checkout до `origin/main`.
6. Собирает PHP image.
7. Поднимает `db` и `redis`.
8. Устанавливает Composer-зависимости.
9. Собирает Vite assets через Node container.
10. Удаляет файловый config cache старого приложения.
11. Запускает `migrate --force`, `optimize:clear`, `config:cache` через one-off `phpfpm` container.
12. Поднимает `phpfpm` и `nginx`.
13. Выставляет права на `storage` и `bootstrap/cache` как `deploy:www-data` с `ug+rwX`.
14. Перезапускает `phpfpm` и `nginx`.

## Нерешенный production-вопрос

Текущая VDS-БД содержит legacy schema старого проекта. Принято решение не накатывать новую кодовую базу на старую БД.

Для первого production deploy используется новая БД:

- `DB_DATABASE=mskbabrandnew`;
- `DB_USERNAME=mskbabrandnew`;
- новый `DB_PASSWORD` в серверном `.env`;
- volume `mskbabrandnew_postgres_data`.

Старая БД остается в volume `mskba_postgres_data` и не должна удаляться. Если понадобятся старые пользователи или площадки, импорт нужно делать отдельной задачей после стабилизации новой версии.

`APP_DEBUG` на VDS уже исправлен на `false`.

## Выполненные проверки

- `docker compose -f docker-compose.prod.yml config --services` - пройден;
- `docker compose -f docker-compose.prod.yml --profile build config --services` - пройден;
- shell syntax deploy script из `.github/workflows/deploy.yml` через `bash -n` - пройден;
- `docker compose -f docker-compose.prod.yml build phpfpm` - пройден;
- `nginx -t` для `docker/nginx/default.conf` через контейнер `nginx:1.27-alpine` с тестовым host alias `phpfpm` - пройден;
- `npm run build` - пройден;
- `php artisan route:list` - пройден, показал 28 маршрутов;
- ручной deploy на VDS - пройден;
- production migrations на новой БД `mskbabrandnew` - пройдены;
- server checkout `git reset --hard origin/main` после deploy - пройден после корректировки прав `storage` и `bootstrap/cache`;
- `https://mskba.ru/` - вернул `200`;
- `git diff --check` - пройден.

## Текущий статус

Выполнен предварительный аудит локальной конфигурации, старого deploy и VDS. Добавлены production Docker runtime и GitHub Actions workflow. Первый deploy новой версии выполнен вручную на VDS.

Workflow обновлен по результатам ручного deploy и готов к следующим автоматическим deploy из `main`. Серверный `.env` уже обновлен на новую БД, а старый volume `mskba_postgres_data` остается на сервере и не используется новым production compose.
