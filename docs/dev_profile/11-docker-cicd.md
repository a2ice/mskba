# 11. Docker, CI/CD и окружения

[← Оглавление](README.md) · [Далее →](12-business-cases.md)

## Docker простыми словами

**Docker image** — инструкция/готовый образ процесса; **container** — запущенный экземпляр image. **Docker Compose** описывает, какие контейнеры стартуют вместе, сети, тома, env, команды и зависимости. Контейнер — не виртуальная машина: использует ядро хоста.

~~~text
Браузер → Nginx → PHP-FPM / Laravel
                       ├─ PostgreSQL 17 (постоянная БД)
                       ├─ Redis 7 (cache / часть queues)
                       ├─ queue workers
                       ├─ Reverb (WebSocket)
                       ├─ scheduler
                       ├─ Telegram workers/poller
                       └─ Gotenberg (PDF/HTML rendering, по потребности)
~~~

**В коде:** [Dockerfile](../../docker/app/Dockerfile), [compose.yaml](../../compose.yaml), [compose.dev.yaml](../../compose.dev.yaml), [compose.prod.yaml](../../compose.prod.yaml), [Nginx](../../docker/nginx/default.conf).

## Зачем отдельные окружения

- **Локальная разработка:** быстрый тест и изолированные зависимости.
- **dev.mskba.ru:** проверка интеграционного поведения перед main и production.
- **mskba.ru:** реальные пользователи и данные.

Compose-файлы переиспользуют общие сервисы, а dev/prod уточняют конфигурацию. Отдельные сети/volumes/secret values должны исключать случайное смешение dev и production.

## Как CI устроен в реальном GitHub Actions

[.github/workflows/ci.yml](../../.github/workflows/ci.yml):

~~~text
PR → main/dev ИЛИ push → dev
        ↓
   checkout
   PHP 8.4 + Node 22
   composer install
   .env.example + app key
   validate compose (prod + isolated dev)
   php artisan test
   npm ci
   node --test (toasts, Reverb browser config)
   npm run build
   guard: нет localhost/test key в JS
        ↓
   для push dev → проверенная сборка → dev deploy
~~~

**CI (Continuous Integration)** — автоматические проверки после изменения. **CD** — доставка проверенного релиза до нужной среды. Здесь dev delivery описан в [ci.yml](../../.github/workflows/ci.yml), production — отдельный [deploy.yml](../../.github/workflows/deploy.yml), который запускается на push в main и вручную по условиям. Не заявляй, что один файл делает все стадии.

## Что показывает этот пайплайн о качестве

1. Проверяем **код и окружение**, не только PHPUnit: Compose конфигурация должна собираться.
2. Проверяем **frontend** и специфичные JS-сценарии уведомлений.
3. Проверяем, что build не содержит локальные Reverb endpoint/ключи.
4. Разделяем dev/prod и ответственность за выпуск.
5. Используем SSH/SCP для доставки на VDS, а не ручное копирование файлов.

## Scheduler и cron

Обычный Linux cron может **каждую минуту запускать** php artisan schedule:run; Laravel Scheduler описывает само прикладное расписание **внутри Laravel**. В текущем Docker Compose также предусмотрен long-running scheduler с **php artisan schedule:work**. Это не конкурирующие бизнес-подходы: cron — внешний таймер, Scheduler — декларация расписания задач.

**Когда scheduler полезен:** истечение срока брони, очистка временных данных, повторная выборка pending outbox, другие периодические задачи. Наличие команды ещё не доказывает, что все сервисы реально включены на каждом окружении.

## Деплой и откат: что говорить

«Мы используем ветки dev и main, запускаем проверку PHP/JS/Compose в GitHub Actions, dev получает проверенную сборку, production выкатывается отдельным workflow. При ошибке смотрим GitHub Actions run, commit SHA и серверные логи; откат требует вернуть совместимый код и учесть обратимость миграций».

**Не говори:** «на 100% zero downtime», «автоматический rollback» или «blue-green», пока это отдельно не подтверждено deployment script и фактической проверкой.

## Вопросы

**«Почему Docker?»** Повторяемая инфраструктура и снижение «у меня работает». Отдельные сервисы стартуют независимо, можно локально поднять похожую схему.

**«Что такое volume?»** Хранилище, переживающее перезапуск контейнера (например, PostgreSQL data).

**«В чём разница CMD и ENTRYPOINT?»** ENTRYPOINT задаёт исполняемую основу контейнера, CMD — команду/аргументы по умолчанию; Compose command может переопределить CMD.

**«Что сделаешь, если тесты зелёные, а deploy упал?»** Разделю build/transport/server/migrations/runtime, проверю run logs, версии образов и env, состояние сервисов, затем безопасно повторю/откачу без затрагивания реальных данных.

[Спецификация Docker](../specification/docker-environment.md)
