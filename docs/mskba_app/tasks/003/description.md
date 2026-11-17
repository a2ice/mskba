# 003 — Маршруты и интеграция Vue 3 / Inertia

**Статус:** На проверке · **Дата:** 2026-10-08 · **Зависит от:** [001](../001/description.md), [002](../002/description.md).

## Цель

Подключить Vue 3 и Inertia к `mskba_app` так, чтобы существующие Laravel-маршруты, авторизация, бизнес-сервисы и production-тема сохранились.

## Реализовано локально

- Установлены `vue`, `@inertiajs/vue3`, `@vitejs/plugin-vue` и `inertiajs/inertia-laravel`. Обновлены `package*.json`, `composer*.json` и Vite config.
- Добавлен отдельный web-файл `routes/mskba-app.php` в `bootstrap/app.php`. Первый маршрут **`/ui-preview`** доступен только при `APP_THEME=mskba_app`; активная legacy-тема получает 404.
- `HandleMskbaAppInertiaRequests` предоставляет root view `theme::layouts.inertia` и серверный проп `auth.user`. Изолированные `js/inertia.js` и Vue `pages/Preview.vue` не запускают jQuery legacy.
- Существующие страницы продолжают жить на Blade; `header.blade.php` и авторизация размещаются снаружи Inertia root и остаются видимыми при переходе к Vue.
- Исходный путь `/app-preview` оказался зарезервированным Nginx для WebSocket `location /app`; перенесён на `/ui-preview` без изменения прокси.

## Проверки

- GET `/ui-preview`: HTTP 200, HTML с `data-page`; X-Inertia + корректный X-Inertia-Version: JSON 200 с `component: Preview` и props от Laravel.
- Vue-экран визуально открылся в headless Chromium без ошибок JavaScript.
- Vite production build успешен во временную директорию (не меняет `public/build`).
- `tests/Feature/MskbaAppPreviewTest.php`: **2 теста, 4 утверждения — успешно**, legacy guard и приложение.
- В сборке остаются предупреждения о больших chunks других entrypoints; отдельно `npm audit` отмечает 2 critical в `concurrently` / `shell-quote`. Не исправлялись в этой задаче, до публикации требуют приоритизации.

## Что ещё нужно

- Визуальная приёмка browser/mobile пользователем и решение о развитии общего Vue application layout.
- Перенос настоящих предметных экранов — дальнейшие задачи; GET `/ui-preview` пока только интеграционный тест.
- При будущем расширении маршрутизации не дублировать domain services, permissions, auth или JSON API без необходимости.

## Статус Git

Локальная `feature/139`. Push/PR/merge/deploy запрещены до отдельной команды пользователя.

[Прогресс](../../progress.md) · [Реестр](../../tasks.md) · [Следующая задача 008](../008/description.md)
