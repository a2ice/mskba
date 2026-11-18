# 326 — Restore Filament production assets after the MSKBA cutover

## Оригинальное описание
18.11.2026: после переноса dev → production в `https://mskba.ru/backoffice` интерфейс Filament отображается без оформления: стандартные элементы и SVG-иконки некорректного размера.

## Диагностика
- `BackofficePanelProvider` регистрирует Filament 5 на `/backoffice`; страница формирует HTML.
- Published Filament assets не хранятся в Git: `public/css/filament/` и `public/js/filament/` находятся в `.gitignore`.
- На staging обновление Composer-зависимостей вызывает `filament:assets` в `.github/workflows/ci.yml`.
- Production `.github/workflows/deploy.yml` устанавливает зависимости и собирает Vite, но не публиковал Filament CSS/JS. `composer.json` не содержит `filament:upgrade` в Composer `post-autoload-dump`.

## Исправление
- После production `composer install` запускать `php artisan filament:assets --no-interaction` в том же PHP Docker Compose-контексте.
- До дальнейшего деплоя проверять наличие и ненулевой размер `public/css/filament/filament/app.css` и `public/js/filament/filament/app.js`.
- База данных, маршруты и права пользователей не изменяются этим исправлением.

## Проверка
- GitHub PR CI должен пройти перед merge.
- После production deployment убедиться, что Asset publish завершился успешно, CSS/JS отдаются сервером, а `/backoffice` корректно оформлен (hard refresh при необходимости).
- В случае остающихся ошибок проверить status/content-type конкретных URL в браузерном Network и записи Nginx/PHP, не запускать повторный data cutover.

## Статус
Подготовлен hotfix на отдельной ветке; production-подтверждение после деплоя.
