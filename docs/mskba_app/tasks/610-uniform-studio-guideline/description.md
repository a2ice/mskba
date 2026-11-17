# MSKBA 610 — Мастерская формы: привести к гайдлайну

Дата: 06.11.2026. Источник задачи — визуальная приёмка модалки и сравнение со страницей редактирования игрока.

## Эталонные UI-примитивы (перед кодом)
- `docs/specification/ui-design-system.md`: обязательные нормы типографики, контраста, контролов и модалок. UI Kit в Filament → Дизайн → Темы оформления → UI Kit.
- `css/player-profile-workspace.css`: `.app-player-workspace__preview`, `.app-player-workspace__editor`, `.app-player-workspace__stage` — размеры/радиусы/светлый фон внутренних панелей.
- `css/modal.css`, `css/account-profile.css`: `.mskba-modal`, `.app-profile__request-header`, `.app-profile__dialog-close` — единая шапка, крестик, закрытие, drag.
- `css/design-system.css`: `.button.primary|secondary|danger`, `.check-label`, `.select-control`, семантические CSS-токены.

## Выполнено
Вместо локальной «сине-чёрной» палитры редактор использует `--surface`, `--field`, `--text`, `--muted`, `--control-line`, `--accent`. Удалены декоративные DRAFT/01 PREVIEW/eyebrow, подпись «не лекало», поясняющие абзацы и footer модалки. Превью одежды помещено в светлую сцену по примеру профиля игрока. Кнопки и радио/чекбоксы берут общие классы темы.

Добавлено поле `sleeve_style` со значениями `sleeveless` (без рукавов) и `sleeved` (с рукавами), мгновенное переключение кройного SVG, сохранение через CRUD, отображение в личной коллекции, валидация и включение в гостевой черновик. Отдельная миграция устанавливает `sleeved` для прежних проектов, сохраняя их внешний вид. Для новых по умолчанию `sleeveless`.

## Проверки
PHP lint, Vite build, общий CI, CRUD и границы доступа; браузерный просмотр desktop/mobile, светлая тема, отсутствие горизонтального overflow; штатный крестик, Escape, drag и guest-save. Только dev, prod/main без изменений. Глобальные компоненты UI Kit не изменяются: используются существующие контракты.
