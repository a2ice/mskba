# MSKBA App

Основа новой темы задачи 139 для реальных сценариев локального приложения.

## Текущий прогресс — 2026-10-08

- Основа темы, стили принятого эталона и каталог перенесены; локальное приложение открывается.
- Первый рабочий `header` с адаптивной навигацией готов и показан пользователю. По замечанию увеличен desktop-отступ после логотипа на 12 px; окончательная визуальная приёмка ещё ожидается.
- HTTP 200, ссылки документации, JS syntax и Vite build проверены; сборка предупреждает об уже имеющихся крупных chunks и unresolved `/images/home-court.png`.
- Vue/Inertia интегрированы в отдельный `/ui-preview`, Vue-попап авторизации работает локально; реальные предметные экраны ещё не перенесены, production остаётся на `mskba_dark`.

Подробности и следующие шаги — в [журнале прогресса](../../../docs/mskba_app/progress.md).

[Начать работу с UI/UX MSKBA App](../../../docs/mskba_app/start_here.md) · [Актуальный прогресс](../../../docs/mskba_app/progress.md) · [Задачи](../../../docs/mskba_app/tasks.md).

- `css/app.css` — Vite entry; подключает локальный Inter, полные токены и `design-system.css` принятого эталона 002-03 (типографика, layout, формы, кнопки, карточки, диалоги, состояния и responsive).
- `assets/` — локальные шрифт с OFL, три изображения стенда и лицензия Tabler.
- `views/partials/icons.blade.php` — оригинальный SVG sprite Tabler 3.46.0.
- [Каталог оформления](catalog/README.md) — образцы на тех же CSS и assets, что будут использовать реальные страницы. `js/catalog.js` изолирован от приложения.
- `js/app.js` — монтирует Vue AuthDialog на Blade-страницах и подключает `js/header.js`; отдельный `js/inertia.js` запускает Vue/Inertia на `/ui-preview`, без legacy jQuery.
- `css/header.css` и `views/partials/header.blade.php` — первый рабочий partial: оформление принятого эталона, пункты через существующий `MainMenu`, desktop dropdown и mobile menu, гостевой вход через маршрут `login`, профиль и уведомления для авторизованного пользователя. Пользователь просмотрел desktop и mobile, замечание по desktop-отступу (+12 px) внесено; повторная визуальная приёмка и полная матрица тестов ещё нужны. Вход открывает Vue-попап; `/login` и `/register` открывают соответствующий раздел диалога.
- `views/layouts/app.blade.php` — серверная Blade-оболочка с header и Vue AuthDialog mount; `views/layouts/inertia.blade.php` — отдельный Inertia root view с тем же header. В `js/pages/Preview.vue` пока находится интеграционный экран.
- `views/pages/system/view_not_found.blade.php` — fallback для неперенесённых страниц.

Общая библиотека Vue-компонентов и реальные предметные экраны ещё не реализованы; первый Vue-компонент AuthDialog и интеграционная Inertia-страница уже есть.
Inter поставляется локально в WOFF2 с кириллицей и весами 100–900. Внешних font/CDN-зависимостей нет.
CSS перенесён полностью из принятого стенда: часть классов описывает его композиции,
а не готовые Vue-компоненты. Семантические классы `.button`, `.panel`, `.field-group`
и остальные будут использовать общие компоненты. Недемонстрируемые в эталоне
элементы (например, range-слайдеры) предстоит согласовать и реализовать отдельно.
По поручению пользователя локальное приложение переключено через `APP_THEME=mskba_app`.
Значение по умолчанию в config и `.env.example` остаётся `mskba_dark`.
Реальные страницы пока не перенесены: ThemeResolver показывает fallback новой темы,
не наследуя страницы mskba_dark. Для локального возврата: `APP_THEME=mskba_dark`
и `php artisan config:clear`. Для assets требуется Vite dev server или production build.

Маршрут `/ui-preview` зарегистрирован в `routes/mskba-app.php` через `bootstrap/app.php`
и доступен только для темы `mskba_app`. `/app` зарезервирован Nginx для Reverb/WebSocket.
Inertia использует web-session и CSRF; auth-попап вызывает существующие JSON endpoints.
Восстановление на сервере пока возвращает 503, Telegram/VK OAuth не проверены.
Юридические документы ещё не перенесены, поэтому локально ссылки ведут на опубликованные страницы `mskba.ru`.
Новая тема не готова для переключения production. [Задачи 003/008](../../../docs/mskba_app/tasks.md).
