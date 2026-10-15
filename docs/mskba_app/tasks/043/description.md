# 043 — Две темы оформления MSKBA App

Статус: локальная реализация, проверка / визуальная приёмка впереди (2026-10-15).

## Контракт

- Единый renderer mskba_app и общие Blade/Vue компоненты. Палитры только CSS custom properties; никаких копий страниц.
- Системные arena_night («Ночная арена») и court_day («Светлый паркет»), неизменяемый slug, запрет удаления; системные темы нельзя снять с публикации.
- Filament → Дизайн → Темы оформления: создание, визуальные цвета, редактор переменных, preview, публикация и глобальная тема. История версий сохраняется автоматически, восстановление доступно менеджеру и само создаёт обратимую ревизию.
- Разрешения backoffice.theme.view / backoffice.theme.manage совместно с backoffice.access.
- Общая тема для гостей/пользователей без личного выбора. Личный выбор хранится у canonical identity, смена CSS без reload.
- Сервер выбирает тему до первого paint; CSS выдаётся версионированным URL с immutable cache.
- Валидируются HEX палитра и содержимое CSS: только один :root, переменные, без глобальных селекторов, URL и импортов.
- CSS компонентов обновляется через токены и общие light-mode адаптации. Нельзя нарушить top-layer modal и работу чатов.
- Локальная ветка feature/design-theme-manager-20261015, основание origin/dev a4abae34.

## Приёмка

- Миграция: встроенные темы, одна глобальная, nullable user preference, rollback.
- Filament: полномочия, CRUD, защита системных тем.
- User flow: guest, personal choice, default, disabled custom fallback.
- Desktop/mobile 320/390/768/1440, формы, сообщения, поповеры, модалки, header.
- Тесты PHP, Vite и реальный браузер. До визуальной приёмки статус не «Принято».
- Никаких push, PR, merge, deploy без отдельной команды.

## Проверено локально — 2026-10-15

- Laravel/PHP: 11 новых тестов, 47 утверждений; доступ Filament суперадмина и запрет обычному пользователю, системные темы, CSS-валидация, глобальная/личная настройка, unpublished.
- Регрессия: AccountProfile/ModalOnboarding — 32 теста, 362 утверждения; notification toasts — 3 JS-теста.
- `npm run build` — PASS (предшествующее предупреждение о крупных чанках); Pint и `git diff --check` — PASS.
- Изолированная SQLite: полная миграция, серверный HTML для гостя с `arena_night` и `court_day` и изменяемым CSS URL — PASS.
- Chromium: real desktop screenshots для обеих тем; мобильный CDP viewport 390×844, `innerWidth=390`, `documentScrollWidth=390`, dialog width 374, x=8–382 — PASS. Нет обрезания/переполнения в проверенной модалке входа.
- Проверка всех авторизованных экранов и визуальное согласование владельца остаются **на приёмку** после доставки dev; это не выполненный production deploy.

## CI follow-up — 2026-10-15

- Исправлен рендер без миграций: до появления `design_theme_settings` используется системная «Ночная арена», ошибки других запросов БД не скрываются.
- Добавлен тест `DesignThemeWithoutTablesTest`; ранее упавшие `MskbaAppPreviewTest` и `MskbaAppRegistrationLegalDialogTest` повторно прошли (16 targeted tests / 95 assertions).
