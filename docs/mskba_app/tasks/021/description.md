# 021 — Контекстная полоса и каркас страниц кабинета

**Дата:** 2026-10-09 · **Ветка:** `feature/139` · **Статус:** Реализовано локально, на визуальной приёмке; без коммита.

## Поручение

1. Добавить под header контекстную строку: breadcrumbs слева и «Действия» справа, ориентир — `mskba_dark`.
2. Убрать дублирующие eyebrow с overview и sidebar, оставить их на страницах только при смысловой необходимости.
3. Разделить `/account` (сводка, пункт «Обзор») и `/account/profile` (пункт «Профиль»).
4. Страницы верхнего уровня аккаунта в `mskba_app` временно показывают только заголовок и подзаголовок; обзор сохраняет приветствие и информационные предупреждения.

## Ограничения

- Только `mskba_app`: не удалять существующие контроллеры, операции, права и реальные экраны `mskba_dark`.
- `/account/privacy/distribution` и модальный onboarding задачи 019 остаются рабочими, не превращаются в заглушки.
- Дочерние предметные маршруты (редактирование секций/площадок, API и другие операции) не затрагивать.
- Breadcrumbs через `BreadcrumbsResolver`, контекстное меню через `ContextSubmenuResolver`, sidebar — через `MenuResolver`; оформлять UI по гайдлайну (цвета, 44px, клавиатура, focus, responsive).
- Не выполнять git commit, push, PR, merge, deploy, fetch и другие операции с origin до отдельного распоряжения пользователя. Ветку не менять, чужие изменения не сбрасывать.

## Критерии

- [x] Реализован доступный раскрывающийся context bar с breadcrumbs и системными действиями; подключён к layout.
- [x] Убраны дублирующие eyebrow страницы обзора и sidebar; функциональные групповые eyebrow формы приватности сохранены.
- [x] `/account` показывает «Обзор», `/account/profile` — «Профиль», аватар header остаётся ссылкой на `/account`.
- [x] Верхнеуровневые разделы новой темы используют заголовок/подзаголовок, а старый кабинет и onboarding остаются рабочими.
- [x] Blade cache, Vite build, Pint, JS/PHP syntax, profile PHPUnit и `git diff --check` пройдены.
- [x] Обновлены задача, реестр, прогресс, продуктовая/техническая документация и проектный UI-контракт.
- [ ] Пользовательская визуальная приёмка desktop/mobile, проверка keyboard/Escape/share на localhost и Safari/WebView.
- [ ] Отдельное подтверждение локального коммита.

## Реализация

- `resources/themes/mskba_app/views/partials/context-bar.blade.php` — контекстная строка на базе действующих resolver; `context-actions-items.blade.php` — вложенные пункты; `css/context-bar.css` и `js/context-bar.js` — внешний вид и progressive enhancement. Меню использует `details/summary`, закрытие по клику снаружи/Escape, system actions: «Назад», «Поделиться», «Помощь».
- `routes/web.php`: новый `GET /account/profile`; для `mskba_dark` перенаправляет на прежний `/account`. `AccountMenu` только в `mskba_app` добавляет «Обзор» и переназначает «Профиль» на новый URL.
- `views/pages/account/index.blade.php`: удалены повторяющийся eyebrow и карточки профиля/уведомлений, оставлены приветствие 020 и серверная плашка 019.
- `views/pages/account/partials/section-heading.blade.php` и десять верхнеуровневых Blade-страниц новой темы: `profile/wallet/roles/teams/venues/notifications/contacts/settings/contracts/sports-sections.index`. Классы общего account layout не заменены. Приватность — исключение.
- В `docs/specification/ui-design-system.md` зафиксирован **проект** визуального контракта 021. До пользовательского одобрения новая строка не считается принятой ревизией.

## Проверки и техдолг

- Финальный контроль: **25 PHPUnit / 182 assertions PASS** (в том числе навигация/legacy, приветствие, onboarding, account layout); Vite build PASS; Blade view cache PASS; Laravel Pint PASS; syntax и `git diff --check` PASS.
- Vite сообщил об известных предупреждениях `home-court.png` и крупных chunks; генерирует изменения в `public/build`. Эти артефакты до решения о составе будущего коммита отдельно не фиксировать и не очищать разрушительными командами.
- Реальную визуальную приёмку на браузерах и размерах 320/440/768/1440 не объявлять пройденной без подтверждения пользователя. Ждём скриншотов/замечаний; commit/push/PR/merge/deploy не выполнялись.

## Уточнения визуальной приёмки 2026-10-09 — фиксирование UI

- Контекстная полоса сделана `position: sticky` на расстоянии `var(--header-height)` от верха, высота **44 CSS px** (минимальный размер интерактивной области сохранён). `scroll-padding-top` учитывает две верхние полосы и 24 px. Bar остаётся ниже header при прокрутке.
- Для desktop sidebar задан sticky offset `header + context bar + 16px`. Контейнер кабинета получает минимальную высоту, иначе на коротких страницах равная высота grid и sidebar не оставляет sticky диапазона. Левое меню имеет собственный скролл при нехватке доступной высоты; на mobile сохраняется раскрываемое `details`.
- Из desktop sidebar убран **весь заголовочный блок**, поскольку повторяет заголовок текущей страницы.
- Изменения в существующей задаче 021, автоматизированная приёмка и дальнейшие скриншоты уточняются до коммита.

- Chrome headless на CSS, импортированном из реальной темы (контрольный длинный desktop layout): после scrollY=550 header top=0, context bar top=80, sidebar top=140 — PASS. На 768px header top=0, bar top=64; sidebar ожидаемо static с переходом в mobile details. Короткий layout не создаёт искусственной прокрутки при полностью помещающемся контенте. Это CSS fixture, не пользовательская browser acceptance локального аккаунта.
- Финально 25 PHPUnit / 194 assertions PASS, Laravel Pint, Blade view cache, JS syntax, Vite (изолированный output), `git diff --check` PASS. Vite предупреждает о крупных существующих чанках. Скриншоты пользователя после поправок необходимы перед коммитом.
