# UI Studio — UI First (21.11.2026)

Задача [#933](https://github.com/a2ice/mskba/issues/933). Регламент принят владельцем; инфраструктура вводится поэтапно. См. [действующий дизайн-гайдлайн](ui-design-system.md) и [скилл](../../.agents/skills/mskba-ui-ux/SKILL.md).

## Оглавление
- [Контур и доставка](#контур-и-доставка)
- [Темы](#темы)
- [Интерфейсы и приёмка](#интерфейсы-и-приёмка)
- [Развёртывание и ограничения](#развёртывание-и-ограничения)

## Контур и доставка

UI Studio — отдельный checkout/runtime Laravel + существующий Vite, целевой host ui.mskba.ru. Это не dev и не production и не новая дизайн-система. На итерациях — прямое обновление файлов в изолированном checkout, при изменении ассетов локальная сборка, без CI/merge/автодеплоя приложения. Для Blade/fixtures достаточно обновления файлов и cache clear при необходимости. Никаких прямых правок рабочих checkout dev/prod. Git-ветки и архивы принятого кода сохраняют историю.

Маршруты /uikit и /uikit/{ui_name} доступны только при APP_ENV=ui-studio и UI_STUDIO_ENABLED=true, с точным host и отдельной HTTP Basic-аутентификацией. Внешний reverse proxy допускает только GET/HEAD для /uikit и статических ассетов; остальные пути закрыты. Никакой общей DB, Redis, файлов пользователей, очередей, cookies, ключей или внешних integrations. Отключены внешние запросы в preview через CSP, формы не отправляются; никакого Vite dev server наружу. Используй собственный APP_KEY, локальную пустую БД при необходимости boot, SESSION_DRIVER=array, CACHE_STORE=array, MAIL_MAILER=log, LOG_CHANNEL=stderr, никаких workers/scheduler; внешнюю сеть runtime блокировать. Basic Auth только по TLS, отдельные credentials, не переиспользовать портал.

## Темы

Различаем кодовый шаблон (config/themes.php) и динамическую тему оформления mskba_app. Внутри mskba_app одна разметка, реальные CSS app.css, data-design-theme=slug, data-appearance-mode=mode, скомпилированные стили DesignThemeCss и опубликованный theme JS. Снимок всех опубликованных тем получаем командой ui-studio:export-themes на доверенном окружении с текущими настройками, только чтение; переносим лишь JSON с визуальными настройками и опубликованным кодом через защищённый канал. Не копируем БД или .env. Снимок должен считаться доверенным исполняемым кодом: перед загрузкой на отдельный origin проверь опубликованные JS/CSS на сетевые вызовы и чувствительные данные. Hash в URL iframe/ассетов не позволяет незаметно подменить тему при обновлении снимка. Не отправляем JSON в git. При отсутствии снимка доступны системные пресеты из config/design_palettes.php с явной маркировкой; это не факт совпадения с текущим Filament. При смене настроек обнови снимок и повтори применимую приёмку. Фиксируй hash темы и дату экспорта.

Legacy mskba_dark, streetball и blank — отдельные шаблоны/ассеты, а не цветовые режимы. Реестр перечисляет кодовые шаблоны; поддержка указывается на уровне сценария. Не выдавай mskba_app в другой палитре за legacy. Для затронутого legacy добавляй собственный реальный specimen/renderer, отдельно проверяй. Необработанные комбинации явно отмечаются как недоступные. В приёмке сверяй все применимые темы и синхронно сравнивай две при одной ширине и fixture.

## Интерфейсы и приёмка

Реестр config/ui-studio.php: slug, title, issue, revision, status, поддерживаемые шаблоны и состояния. Страница интерфейса использует отдельный Blade в resources/views/ui-studio/cases/{slug}; подключает реальные компоненты по include/Vue import, не копии. Локальные CSS/JS и fixtures — рядом с задачей; не меняют Core до согласования. JS, привязанный к API, получает локальный адаптер; реальные запросы запрещены. Новый сценарий добавляется явной записью в реестр. На одном URL можно переключить тему, ширину и fixture; compare использует два iframe с одинаковыми шириной и состоянием.

До выдачи ссылки: проверить адаптивность и состояния, отметить базовый SHA и hash темы. Ревизию согласовывает владелец, агент фиксирует её и переносит принятую реализацию из task branch в приложение; сохраняет snapshot принятого кода. Включение Core-изменений — только в интеграционном PR вместе с гайдлайном и эталоном. После приёмки обычный CI/merge/deploy и проверка интеграции, не повторная вёрстка. Мелкие текстовые/косметические исправления могут идти напрямую.

## Развёртывание и ограничения

Первый каркас: каталог, 1440/768/390/360, переключатель опубликованных тем, сравнение, iframe, реальный общий Blade chat-composer в пилоте с локальным вводом/счётчиком и отправкой mock. Это не полный сценарий чата и не готовая серверная инфраструктура. Подключение иных кодовых шаблонов, реестр сохранённых ревизий, шапка/навигация с fixtures, визуальная проверка и доставка на сервер — следующие этапы #933. Состояния пилота проверяются после запуска.

В отдельном checkout с зависимостями:
```sh
# Не выполнять на рабочем checkout dev/prod.
# Завести отдельное окружение, не копировать .env портала.
# APP_ENV=ui-studio, UI_STUDIO_ENABLED=true, UI_STUDIO_HOST=ui.mskba.ru
# UI_STUDIO_USER, UI_STUDIO_PASSWORD_HASH (password_hash, bcrypt/argon2)
npm ci
npm run build
php artisan route:list --path=uikit
```
Экспорт на доверенном checkout, читающем актуальные темы:
```sh
php artisan ui-studio:export-themes > themes.json
```
Перенести успешно сформированный файл атомарно в storage/app/private/ui-studio/themes.json отдельного UI-runtime; ограничить права. Не включать APP_DEBUG на публичном preview. Настроить nginx TLS и allowlist /uikit, /build и необходимых статических путей; закрыть прямой доступ к PHP/Vite. Проверить HTTP 401 без учётных данных, 404 вне контура, отсутствие внешних запросов и изменений данных, затем пилот. До фактических проверок не считать контур готовым.

### Автоматическая доставка preview

Ветка `ui-preview` — отдельная публикационная ветка без PR в main/dev. Workflow `.github/workflows/ui-preview.yml` срабатывает на push только в неё (не добавлять [skip ci] к preview-коммитам: это отключит и публикацию). Использует существующий SSH-доступ GitHub Actions, работает только в /var/www/mskba-ui и с mskba-ui-php, блокирует параллельные публикации и проверяет network=none. При изменениях frontend-ассетов выполняет локальную сборку, при Blade-only — только обновление файлов и кеша. Composer, маршруты и прочую конфигурацию runtime не изменяет автоматически. После публикации проверяет маршруты и отсутствие публичного доступа; визуальная приёмка в браузере остаётся обязательной. На первом этапе доступ SSH общий с обычным deploy; выделенный ключ с ограниченной серверной командой — последующее усиление изоляции. Не открывать PR из ui-preview в product-ветку: переносить только принятые изменения из локального кандидата в целевой PR.

### Consolidated Studio/Kit — compatibility update, 23.11.2026

The already deployed isolated `ui-preview` worktree contains four UI screens and newer local revisions, notably `event-detail` r10 and `unified-chat` r6. These are preserved. Do not replace the complete `ui-preview` branch with `main` or the obsolete unified-chat r3 fixture.

The new code from #934 is integrated as shared studio infrastructure (iframe bridge, single-use HMAC tickets, review manifests and acceptance UI). `ui-preview.yml` allows only the audited `routes/ui-studio.php` and `config/ui-studio.php` runtime diffs; other routing/config/dependency modifications still fail closed.

The isolated Studio `.env` must use `SESSION_DRIVER=file` and `CACHE_STORE=file` before accepting tickets, with a new shared secret used only by the bridge and Studio. Never copy APP_KEY or Laravel sessions from production. Production `UI_STUDIO_BRIDGE_ENABLED` remains false until the isolated route, proxy POST allowlist and secure session are verified. Review storage stays inside the Studio checkout.

Cases describe the exact source files used to calculate a SHA-256 fingerprint. Proposed reusable kit changes are manually classified, not machine-discovered; only the chat case currently contains explicit suggestions. Accepted decisions are local reviews, not automatic CSS/JS promotion to production.
