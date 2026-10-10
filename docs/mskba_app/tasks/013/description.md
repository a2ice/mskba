# 013 — Full-width main, section inner и sticky-сайдбар аккаунта

**Дата:** 2026-10-09 · **Основная задача:** #139 · **Ветка:** feature/139
**Статус:** Реализовано локально, на визуальной приёмке. Коммит / push — отдельно.

## Исходное замечание и контракт

Пользователь обнаружил два уровня нежелательных ограничений:
`<main class="app-shell container">` делает весь сайт ограниченным по ширине;
сама новая страница согласий дополнительно имеет `width:min(100%,1120px)`.
Нужна архитектура **main → section → необязательный inner.container**:
полноширинная секция / баннер возможны без контейнера, а обычные
экранные секции содержат `.container` внутри себя.

Страницы аккаунта должны использовать общую оболочку с левой навигацией.
Источник навигации — действующий
`App\\Presentation\\Navigation\\MenuResolver::resolve('account')` /
`AccountMenu`, без копирования и ручного перечисления меню. Сайдбар
фиксируется при прокрутке ниже header через CSS sticky и заканчивает
липнуть на нижней границе родительской секции.

## План

- [x] Изучить общий layout, старую `section-sidebar`, sidebar menu,
  `AccountMenu` и базовые компоненты гайдлайна.
- [x] Убрать `.container` с корневого `main` в Blade и Inertia.
      Разместить inner на уровне отдельных страниц и секций
      (регистрация, вход, системная заглушка, юридический текст).
- [x] Удалить локальное ограничение ширины новой страницы приватности;
      использовать стандартный `.container` лишь внутри её section.
- [x] Создать переиспользуемый account layout: секция, внутренний
      контейнер, CSS grid aside + контент. Отдельный account sidebar
      через существующий `MenuResolver` и badge-значения.
- [x] Desktop: `position:sticky` на sidebar, отступ от sticky header,
      прокрутка длинного меню внутри viewport, остановка в пределах
      секции. Mobile: доступное нативное раскрывающееся меню, без
      горизонтального overflow; учитывать безопасные зоны.
- [x] Проверить отсутствие дублирующего `main`, семантику section/aside,
      корректность активных пунктов, Blade, Vite, маршруты и адаптивность
      320/360/440/768/1024/1440, sticky при scroll и stop у конца секции.
- [x] Документировать компоненты и вынести на визуальную приёмку.

## Границы

Переиспользовать текущие токены, `.container`, `.panel`,
`.button`, `.badge` и существующий menu resolver.
Не менять обработку согласий/миграции/legacy menu; задача 012
продолжает визуальную приёмку и остаётся без коммита.
Новая оболочка применяется только к страницам аккаунта;
главная тема `mskba_dark` не затрагивается.
**Не делать push, merge или коммит без нового явного указания.**

## Результаты и проверка (2026-10-09)

- Корневой main стал полноширинным в Blade и Inertia. Все существующие страницы новой темы получили standard inner.container внутри section там, где он нужен.
- Экран приватности использует reusable account layout: одна section, стандартный inner container, grid с left aside 260 px и fluid content. Убрано дополнительное ограничение ширины 1120 px и ограничения текста/заголовка.
- Sidebar рендерится через MenuResolver для account (доступность, счётчики, ссылки). В desktop sticky расположен на 16 px ниже header и перестаёт прилипать после конца section; собственное меню прокручивается при нехватке высоты окна. Mobile — нативный details/summary, свёрнутый по умолчанию.
- Chromium: 320, 360, 440, 768, 1024, 1440 px PASS. Root main/section полноширинны, 0 горизонтального overflow; sticky проверен на середине и после выхода за section.
- PHPUnit 17 тестов и 91 утверждение PASS; Blade view:cache, PHP Pint, Vite build — PASS.
- Реализация локальная и ожидает визуальной приёмки. Не выполнены commit/push/merge/deploy.

## Уточнение в рамках последующей приёмки 021

Исторический offset `header + 16px` из первоначальной проверки 013 уточнён для новой sticky-полосы задачи 021: desktop sidebar теперь располагается ниже `header + context bar + 16px` и получает min-height родительского grid для коротких страниц. В mobile sidebar по-прежнему раскрываемый, не sticky. См. [021](../021/description.md).

## Layout refinement 2026-10-10: heading above sidebar + content

The shared `theme::layouts.account` now renders an optional `@section('account-heading')` above `.app-account-layout` (the `aside + content` grid), spanning the entire `.container` width. Account pages declare their title/subtitle in that slot, using `theme::pages.account.partials.section-heading` or their existing custom intro. This avoids duplicated heading markup inside the content column. First-time privacy onboarding remains a separate full-width shell without the sidebar and keeps its existing behavior. No changes to `MenuResolver` or role-driven sidebar update JS.

Desktop sidebar still uses CSS `position:sticky; top:calc(var(--header-height) + var(--context-bar-height) + 16px)` inside the grid. The heading now precedes the grid, so it naturally scrolls away before sticky engages; the aside scroll container and mobile non-sticky accordion remain as before. Common heading margin is applied by `.app-account-section__heading` with mobile adjustment. Recheck sticky by scrolling on a long account page after staging deploy.
