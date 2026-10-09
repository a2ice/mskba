# 026 — Аккаунт: профиль и личные данные (MSKBA App)

Дата: 2026-10-09. Ветка feature/139. Статус: локально зафиксировано по просьбе пользователя, local-only.

## Функциональное задание

- Экран /account/profile вместо заглушки. Аватары: загрузить / посмотреть существующие (до 3) / сделать основным / удалить, используя действующие avatar routes, нормализацию WebP, ownership и лимиты 5 МБ. Не дублировать avatar business logic и не изменять legacy.
- Логин username — отдельно, без изменения в этом шаге. Публичный nickname отдельный от username, основной адрес /users/{nickname}; fallback на /users/{username}, а затем ID по PublicUserProfileService::url. Изменение через уже существующий account.nickname.update (JSON): латинская буква первой, далее a-z0-9_, длина 3–30, нижний регистр, уникален относительно nickname и username других, включая deleted/canonical aliases; не писать в поле username.
- ФИО (имя, фамилия, отчество), дата рождения (возраст вычисляется из неё), пол male/female. Для неподтверждённого аккаунта все поля доступны к редактированию. Для подтверждённого пользователя (UserStatusEnum::CONFIRMED): имя, фамилия, дата рождения и пол — неизменяемые без отдельной заявки, **не только UI, но и на сервере**. Кнопка запроса открывает честную заглушку, не отправляет данные и не обещает обработки. Отчество отдельно редактируемо (его не было в списке защищаемых пользователем полей).
- При отсутствии Profile создать его по существующей связи, не терять данные. Сохранение с валидацией и CSRF, защита owner/canonical, transaction. Неподтверждённые изменения проходят без массового назначения system roles.
- Действующие onboarding guards применяются без исключений; страница доступна к просмотру до завершения последнего шага, мутации ограничены общей middleware.
- Использовать существующие panel/form/modal примитивы из docs/specification/ui-design-system.md без новых глобальных компонентов; новая модалка — одноразовая заглушка.

## Проверки

- Режимы confirmed/unconfirmed, alias identity, пустые поля, валидация email/username collisions, nickname JSON.
- Реальные avatar routes в новой теме, upload/activate/delete, 3-slot limit, ownership, отличия от публичного AI reference.
- Защита подтверждённых полей на backend при прямом запросе, состояние после отказа, подтверждённое отчество, точный возраст, переключение пола, сохранение.
- Legacy theme /account по-прежнему работает, /account/profile перенаправляет назад, нет регрессии регистрационного Wizard.
- Desktop/mobile, focus, dialogs showModal, доступные подписи, тексты на «ты».
- PHPUnit + Blade + Pint + Vite isolated build, git diff --check, Chrome fixture. Визуальная приёмка пользователя отдельно.

## Режим Git

Задача 025 зафиксирована локально коммитом fd779d44. Никаких новых коммитов, push/PR/merge/deploy без отдельного явного поручения.


## Реализация (2026-10-09)

- GET account.profile теперь обслуживает AccountProfileController. mskba_app загружает canonical user, его профиль и актуальный список аватаров (load, а не loadMissing — галерея должна перечитываться после мутаций). Legacy mskba_dark, как и раньше, перенаправляет на /account.
- resources/themes/mskba_app/views/pages/account/profile.blade.php, css/account-profile.css, js/account-profile.js (через общие entrypoints): три раздела «Аватар», «Имя пользователя и ссылка», «Личные данные». Отступ после подзаголовка 35px, принятый в задаче 025. Desktop две колонки формы, mobile одна.
- Аватары подключены к прежним account.avatar.store/activate/destroy, с CSRF, ограничением до 3 в existing handler, 5 МБ JPEG/PNG/WebP, нормализацией WebP, проверкой владельца. Загрузка автоматически отправляет нативный multipart POST, показывает состояние загрузки. Галерея позволяет сделать другой аватар основным и удалить существующий. При отсутствии записи Profile GET ничего не создаёт, сначала требуется сохранить данные.
- Логин username отображается как неизменяемое значение; публичный nickname — отдельное поле через существующий account.nickname.update JSON. Действующие NicknameSuggestionService и PublicUserProfileService::url сохраняют URL инварианты: никнейм, иначе username, иначе id; 3–30 символов, a-z0-9_ с первой буквой, lowercase, проверка коллизий, включая soft-deleted пользователей и username. Ссылка обновляется без reload только после успешного ответа. При валидации старая ссылка сохраняется.
- PATCH account.profile.update реализован новым UpdateAccountProfileController, только mskba_app, с CSRF/auth и rate limit, транзакцией/lock и проверкой canonical identity. У неподтверждённого аккаунта можно править first_name, last_name, middle_name, birth_date, gender; birth_date хранится вместо вычисленного поля age. У confirmed аккаунта first/last/birth_date/gender read-only (и не имеют name в UI), плюс явный серверный HTTP 403 для прямых запросов к этим полям, включая alias-аккаунт. Отчество пока редактируется отдельно, поскольку оно не перечислено в ограниченных пользователем полях.
- Кнопка «Запросить изменение подтверждённых данных» показывает только нативный modal showModal() с фокусом заголовка и возвратом к кнопке; никаких заявок не создаётся. Существующие onboarding/verification guards оставлены без обхода. Обращение в текстах на «ты».
- Старые theme-specific avatar/public-profile тесты привязаны к mskba_dark (они проверяют точные классы прежнего HTML); новая тема проверяется отдельным MskbaAppAccountProfileTest. Логика старой темы не изменялась.

## QA и оставшиеся границы

- [x] 10 профильных PHPUnit-тестов / 88 asserts: authenticated, unconfirmed update, confirmed protection и разрешённое отчество, validation, alias bypass, fallback публичного URL, nickname JSON collision, аватары upload/activate/delete, отсутствие Profile, legacy.
- [x] Общая регрессия аккаунта, ролей, онбординга, публичных профилей, nickname и аватаров: **91 тест / 790 утверждений PASS**.
- [x] Laravel Pint (7 файлов), php lint, Blade view cache, JS syntax, isolated Vite build и git diff --check PASS. Предупреждения о крупных чанках Vite существовали ранее.
- [x] Headless Chrome fixture с реальными CSS/JS на desktop и узком экране: 2→1 колонки без горизонтального overflow, nickname success обновляет ссылку, 422 оставляет прежнюю ссылку, загрузка аватара передаёт активный file input, legal-style dialog showModal/close с возвратом фокуса PASS.
- [ ] Реальная визуальная приёмка пользователем на localhost:8000/account/profile (в том числе выбор аватаров, открытие заявки и мобильная верстка).
- [ ] Настоящая система приёма/рассмотрения заявок на изменение подтверждённых сведений остаётся будущей задачей; сейчас сознательная заглушка. Не реализовывать без согласования.
- [ ] Никакого нового commit, push, PR, merge или deploy для 026. Предыдущий локальный коммит 025: fd779d44.

### Визуальная правка: убрать дублирующиеся отступы секции (2026-10-09)

- По замечанию пользователя из DevTools убран лишний 64px общесистемный padding-bottom у app-account-overview; новое базовое значение — 35px с нулевым дополнительным margin у вложенного intro. Удалён локальный override для app-profile-page как дублирование.
- То же применено к «Ролям» (убраны 2 override), но публичные страницы команд с заголовком без app-account-overview сохранили 24px. В «Обзоре» плашка незавершённой регистрации сохраняет привычные 24px до плашки через собственный margin-top.
- Проверено реальными стилями в Chrome DOM fixture: роли/профиль ровно 35px, внутренний блок обзора 24px, публичные команды 24px; 1440/768/500px, без горизонтального overflow. Визуальная приёмка пользователя продолжается, никаких новых commit/push/PR/merge/deploy.

### Follow-up: profile controls and identity UI (2026-10-09)

- Gender dropdown now uses the approved select-control with Tabler chevron-down, retaining native select behavior.
- Avatar upload now lives inside the 112px circular avatar. A Tabler user symbol replaces the initial letter for the empty state, and a compact arrow indicates upload. The whole circle opens the native file picker. The separate upload button was removed; existing server upload validation and handlers remain in place.
- The login username input and its explanation were removed entirely from Account/Profile. This supersedes the earlier draft describing a readonly login field. Login remains a server-side identity property; displaying it is deferred to a future Account/Settings task, not implemented here.
- Public nickname remains editable and has explanatory text about its use in public profile URLs; the URL fallback to username remains unchanged.
- Browser QA verified the avatar click, enabled file input, one native submission, loading status, approved select arrow, nickname URL update, and responsive layout. Profile PHPUnit: 10 tests, 97 assertions before final regression. Local-only; no new commit.

Final UI follow-up QA (2026-10-09): 91 PHPUnit tests / 799 assertions PASS across account/profile, avatars, nickname, roles, onboarding and public profiles. Pint 7 files, Blade view cache, JS syntax, isolated Vite build and git diff --check PASS. Existing Vite large-chunk notices remain. The task is still in visual acceptance, without a new commit or push.

### Avatar upload badge alignment and account confirmation FAQ (2026-10-09)

- The small avatar upload arrow now sits directly below the profile glyph, centred along the 112px avatar circle. The CSS uses left:50%, translateX(-50%), bottom:5px instead of right-offset positioning. The entire avatar still remains the accessible upload target; file handling has not changed.
- For UserStatusEnum::UNCONFIRMED, the existing verification status badge is now a button that opens the Modal 1.1 dialog "How to confirm an account". Confirmed and blocked statuses remain plain status labels. The modal uses showModal(), a noninteractive heading for focus, standard close controls, native Escape, scrollable body and restored trigger focus when closed.
- Content is sourced from the system FAQ "First steps" / section "How to confirm an account" (faq.welcome, account-confirmation anchor). A new WelcomeAccountConfirmationGuide service extracts only the targeted section from a published FAQ and renders it via the existing sanitized ContentBodyRenderer. Changes to published FAQ content appear in the popup. If the FAQ record is absent, the text matches the existing legacy FAQ fallback. Draft and archived FAQ content is never exposed; the popup shows an unavailable notice instead.
- Feature regression: fallback text, edited published CMS section, isolation from adjacent sections, draft non-disclosure, HTML script sanitization, and confirmed user with no confirmation-popup trigger. Legacy FAQ page feature tests are pinned to mskba_dark, matching their expected markup without changing FAQ features.
- Chrome layout fixture with current CSS/JS: the avatar arrow and profile icon have identical horizontal centres (0px offset) at 1440/768/500; badge remains within circle. FAQ dialog opens in top layer, heading focused, native close restores focus, no layout shift or horizontal overflow.
- Final regression: 100 PHPUnit tests / 877 assertions PASS, Laravel Pint, Blade, JS syntax, isolated Vite build and git diff --check PASS. Pre-existing Vite large-chunk warnings remain. Awaiting real localhost visual acceptance; no commit, push, PR, merge, deploy.

### Account status placement and contextual verification (2026-10-09)

- The verification status badge was removed from the Profile personal-data card and moved to the Account Overview heading. It is rendered only when the canonical account is UNCONFIRMED. Clicking the Overview badge opens the existing sanitized Welcome FAQ confirmation guide in a single native top-layer dialog, now rendered only on Account Overview.
- The shared context Actions menu has a verification shortcut only on account routes for an UNCONFIRMED canonical user. The Actions summary and the verification link each display one green attention dot. The dots use the theme --success token and respect prefers-reduced-motion; the dot diameter was refined from 8px to 5px (including a smaller pulse halo).
- Final behavior: the Actions menu entry is a real link to the existing account.confirmation page, not a popup trigger. On the account.confirmation route itself, the verification shortcut and its attention dots are hidden to avoid a redundant call to action. Outside account routes, or for confirmed users, these shortcuts and attention dots are absent. The Overview badge remains an independent FAQ-information action.
- The avatar image is still the single accessible upload target, but its small upload-arrow overlay was removed entirely; only the Tabler user placeholder remains when there is no uploaded avatar.
- The birthday field now has only the computed age caption, e.g. 38 лет. The former verbose Age: ... calculation explanation was removed. If age is not available, no age caption is shown.
- Separate tests assert conditional placement, scope and destination of actions, confirmation page exclusion, canonical-state guards and FAQ excerpt isolation. No new commit, push, PR, merge or deploy; this remains part of Task 026 visual acceptance.

### Actions indicator state transition (2026-10-09)

- The 5px green attention dot is visible beside the closed Actions summary. When its native details menu is open, the summary dot becomes invisible and its animation stops, while the dot beside the "Confirm" destination in the expanded menu remains visible/pulsing. Closing the menu restores the summary dot.
- Implemented only in context-bar.css with a direct selector targeting the summary dot in details[open]; the existing markup, destination /account/confirmation, and reduced-motion setting are unchanged.
- The summary dot keeps its reserved inline box (opacity:0 rather than display:none) so the Actions label and caret do not move horizontally. Browser QA with real styles at 1440/768/500px: closed summary opacity=1, open summary opacity=0 and animation=none, open menu item opacity=1 with original pulse, trigger widths unchanged, no horizontal overflow. No commit, push, PR, merge or deploy.

### Context Actions label microcopy (2026-10-09)

- Shortened the visible verification action to exactly "Подтвердить акк..." as requested. The full accessible aria-label remains "Подтвердить аккаунт"; the destination account.confirmation and pulsing indicator states are unchanged.
- Blade cache and 15 profile tests / 186 assertions PASS. Local-only, no commit or remote action.

## Local commit checkpoint (2026-10-09)

The user authorized a local-only commit of Task 026 and a development pause. This checkpoint includes the final Actions microcopy, profile/avatar/FAQ controls and related corrections. Generated public/build assets are explicitly excluded. No push, PR, merge or deploy is authorized.
