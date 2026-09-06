# 139 — Архитектура Vue и переходный режим

Статус: **предложение для согласования**, 2026-09-06. ADR-139-003 не принят.
Исходники проверены в `feature/139` на `dbd05a9e`; приложение не изменено.
Основания: [охват](scope.md), [требования 003](subtasks/003-frontend-architecture.md),
[принятый дизайн 1.0](../../specification/ui-design-system.md).

## 1. Проверенные исходные условия

| Область | Наблюдение и источник относительно корня репозитория |
| --- | --- |
| Темы | `config/themes.php`: dark, streetball, blank; `app/Presentation/Theming/ThemeResolver.php`: глобальная тема, один namespace, fallback в `pages.system.view_not_found`, не наследование dark |
| Регистрация | `app/Providers/AppServiceProvider.php`: singleton resolver, namespace при boot; composers добавляют `siteSummary` и нормализуют `game.recruitment_mode` |
| HTTP | `routes/web.php` и отдельные route-файлы сохраняют серверные URL; `bootstrap/app.php` содержит session/actor middleware и JSON exception negotiation |
| Frontend | `package.json`, `composer.json`, `vite.config.js`: Laravel `^13.8`, PHP `^8.3`, Vite `^8.2.0`; Vue/Inertia отсутствуют; entrypoints тем перечислены явно |
| Авторизация | `AuthController::shouldReturnJson()` включает `ajax()`; `RegisterRequest::failedValidation()` самостоятельно отдаёт JSON 422 для AJAX. Это конфликт с обычными Inertia-запросами, не готовый Inertia-контракт |
| Данные | `welcome.blade.php` напрямую запрашивает события, площадки, команды, турнир и контент; dark layout запрашивает профиль/Telegram account. `VenueController::index()` уже использует `ListVenuesHandler` |
| Telegram | `TelegramMiniAppController::home()` устанавливает session-флаг `telegram_mini_app_context`; dark layout сохраняет контекст при дальнейших переходах. Это не доказательство авторизации |
| Telegram bootstrap | `js/features/telegram-mini-app.js`: DOMContentLoaded, SDK либо hash `tgWebAppData`, CSRF-защищённый JSON POST, затем `location.replace(start_destination)` |
| Deep links | `TelegramMiniAppStartDestinationResolver`: event, coordination, rental_coordination, content, notification; адрес назначения определяется сервером после проверки init data |
| Аналитика | `resources/views/partials/analytics/yandex-metrika.blade.php` инициализирует счётчик при загрузке документа; мягкие переходы потребуют отдельного учёта |
| Окружение | Локальный `node --version`: `v20.20.0`; `compose.prod.yaml` содержит Node 22 для сборки, но не постоянный SSR-сервис |

Проверка выборочная, достаточная для предложения, не аудит всех 136 GET-маршрутов
из 001. POST/PUT/PATCH/DELETE, формы и permissions раскрываются по каждому flow
перед его переносом. Ни runtime-совместимость Inertia, ни Mini App здесь не проверены.

`main` на момент исследования — `61145b73`, расхождение с HEAD: 2 коммита только
в feature и 69 только в main. Перед изменением приложения нужен отдельный разрешённый
шаг интеграции main и повторная сверка затронутых контрактов. Не переносить вслепую
старую реализацию поверх новых homepage/navigation/deployment-изменений. Пять новых
hero PNG опубликованы в main, но не присутствуют в этой ветке автоматически.

## 2. ADR: варианты и рекомендация

| Вариант | Плюсы | Цена и ограничения | Вывод |
| --- | --- | --- | --- |
| Vue islands в Blade | Минимальный переходный риск; готовый HTML/SEO; прежние формы и URL | Межстраничные перезагрузки остаются; постоянный shell и состояние между страницами требуют дополнительной навигационной системы | Запасной вариант, если отдельный SSR-процесс неприемлем; не обещает SPA-поведение |
| Vue + Inertia + SSR | Laravel остаётся владельцем маршрутов, sessions и сценариев; Vue-shell сохраняется между перенесёнными экранами; один набор Vue-компонентов для HTML и hydration | Адаптация HTML/JSON/redirect контрактов; SSR runtime, сборка и мониторинг; не автоматическая обёртка над имеющимися JSON endpoints | **Рекомендуется** для согласованного application-like UI |
| Отдельный Vue SPA + API | Независимый frontend; API пригоден нескольким клиентам | Отдельные routing/state/API-контракты, auth/errors, SEO/SSR и deployment; существенно больший объём до первого flow | Не оправдан текущей задачей темы; не вводить второй backend |

Предлагается Vue 3 + официальный Laravel/Vue адаптер Inertia, без Vue Router:
URL, route binding и authorization остаются в Laravel. JSON endpoints используются
для небольших интерактивных запросов, не вместо Inertia page responses.
Компоненты — Composition API/SFC; локальный TypeScript для новой темы и DTO-контрактов
предлагается отдельно от существующего JS. Pinia, глобальный event bus и новая
универсальная API-платформа в начальный состав не входят.

Официальная документация сейчас обозначает Inertia 3 текущей major-версией.
Предлагается проверить её первой, но точные версии пакетов пока не утверждены:
до установки проверить Composer requirements, npm engines/peerDependencies для
Laravel 13, PHP, Vite 8, Vue, адаптеров и TypeScript; записать совместимый набор
и зафиксировать lockfiles. SSR Inertia 3 требует **Node 22+**. Локальный Node 20
недостаточен для такого SSR; смена версии не выполнена и требует согласования.
Не устанавливать новый Laravel starter kit поверх существующего приложения.

## 3. Границы и предлагаемая структура

Рабочее имя — **MSKBA Arena**, alias `mskba_arena`: пока не утверждены.

| Место | Ответственность |
| --- | --- |
| `resources/themes/mskba_arena/views/app.blade.php` | Минимальный Inertia root, meta/SSR head, единственный frontend entrypoint; без бизнес-запросов |
| `resources/themes/mskba_arena/js/app.ts`, `ssr.ts` | Browser bootstrap и явная SSR-точка входа, согласованные plugins/page resolution |
| `js/Pages/{Context}/`, `js/Layouts/` | Страницы и постоянный shell; SSR-safe импорты |
| `js/Components/ui/`, `js/Components/domain/` | Общие токенизированные primitives и предметные композиции |
| `js/Composables/`, `js/Adapters/`, `js/Types/` | Lifecycle/state, границы browser/Telegram/realtime/analytics, типы props |
| `resources/themes/mskba_arena/css/` | Принятые tokens, reset и общие styles; не копия Bootstrap |
| `app/Presentation/Theming/` | Request-scoped выбор renderer, реестр перенесённых страниц и переходная политика |
| `app/Modules/{Module}/Presentation/` | Подготовка page props и отображаемых DTO; auth/policies и use cases переиспользуются |

Имена новых классов уточняются при реализации, не создавать пустые слои заранее.
Практический паттерн здесь — presentation adapter: меняется форма ответа, а не
предметные правила. Запросы чтения переиспользуют существующие application services;
новые read services нужны лишь там, где сегодня SQL живёт в Blade.

Новая root-страница не импортирует dark/shared entrypoints с Bootstrap/jQuery,
Tom Select и DOMContentLoaded-enhancers. Из общего JS переиспользовать только
модули без скрытых DOM-побочных эффектов после проверки. CSS новой темы подключается
только её root; scoped styles не заменяют эту изоляцию. Legacy открывается отдельным
документом, не вставляется HTML-фрагментом в Vue. Существующие Vite inputs сохраняются.
Inter WOFF2 размещается локально с лицензией; `@tabler/icons-vue` — официальные
явные imports, версия согласуется с принятым Outline 3.46.0. Визуальный каталог 004
использует настоящие UI-компоненты, а не второй статический набор.

## 4. Выбор темы, preview и маршрутизация

Предлагаются отдельные настройки rollout `off / preview / on` и реестр разрешённых
**имён страниц/маршрутов**, а не переключение всего приложения через `APP_THEME`.
Точный формат конфигурации закрепить в 004. Не использовать wildcard «все GET»:
search, download, callbacks и snapshot — не страницы.

Порядок выбора для HTML-страницы:

1. Администрация, `/telegram`, `/integrations/main` и текущий Telegram session-контекст
   всегда получают `mskba_dark` в первой версии, включая дальнейшие публичные URL.
2. Если rollout выключен либо запрос не допущен в preview — прежняя настроенная тема.
3. Если rollout включён/preview разрешён и конкретная страница есть в реестре — Arena.
4. Остальная публичная часть и аккаунт в режиме Arena — целиком dark.

Fallback — явная граница страниц, не поиск отдельных partials в другой теме.
На отсутствие зарегистрированного Vue-компонента реагировать как на дефект сборки,
а не незаметно скрывать его legacy-страницей.

Существующий static `ThemeResolver::page(): Illuminate\Http\Response` нельзя
механически заменить возвращением `Inertia\Response`: типы контроллеров и callers
тоже должны быть согласованы. Предложение — отдельный page responder на переносимых
страницах, оставляя legacy API совместимым. Он выбирает Blade/DTO/Inertia до
рендеринга, без исполнения Blade для извлечения данных. В 004 сначала контрактный
spike, затем применение responder по страницам.

Выбор выполняется после доступности session и matched route, до controller/view.
Resolver, namespace `theme::`, layout и `viteInputs()` обязаны видеть один
request-scoped результат. Текущее singleton/boot-поведение требует адаптации,
включая очистку кэша найденных views при смене пути. Не сохранять выбор пользователя
в process-global состоянии; проверить последовательные запросы разных контекстов
в одном процессе. Админка не получает Inertia даже при ошибочном заголовке клиента.

### Preview

- На local/staging: отдельное CSRF-защищённое действие включения preview для текущей
  сессии; допускается локальный гостевой preview для проверки guest flow.
- Вне local — только явно разрешённые тестировщики и включённый серверный flag.
  Непроверенный `?theme=...` не даёт права включать preview или выбирать путь view.
- URL предметных экранов остаются прежними; preview не создаёт вторую публичную
  структуру `/new/...`. Выход из preview — явное действие и полная загрузка страницы.
- Preview ответы: `noindex`, private/no-store. Он использует обычные permissions и
  может выполнять реальные действия: тестовые данные/аккаунты, не production-заявки.
- Для сравнения нового и старого UI — разные browser sessions. Query-флаг не
  распространять на подписанные ссылки и auth callbacks.

### Переходы и ответы

Общий `AppLink` получает серверный URL и разрешённый тип `inertia/document/external`.
Не строить собственную таблицу Laravel routes во Vue. Клик, Ctrl/Cmd-click,
открытие в новой вкладке и прямой GET должны работать по одному URL.

| Переход | Поведение |
| --- | --- |
| Arena → Arena | Inertia visit, shared shell без повторной загрузки документа |
| Arena → dark/admin/Telegram/download/external | Обычная ссылка, полный переход |
| dark → Arena | Обычный GET, новый root с Vue |
| Устаревшая Arena-ссылка после rollback или изменения реестра | Для Inertia GET — `Inertia::location()` (409 + `X-Inertia-Location`), затем обычный GET; не HTML в Inertia error modal |
| Мутация → Arena | Post/Redirect/Get с 303, затем page props |
| Мутация → legacy | Выполнить действие один раз, затем location visit к безопасному GET; никогда не повторять POST через window.location |

На границах middleware/exception/auth redirect тоже требуется protocol-aware ответ.
Flash не должен теряться на промежуточном Inertia GET, который переводится в full
reload: reflash/keep только предназначенных следующему экрану значений. Заголовок
`X-Inertia` определяет формат, **не права**, и учитывается лишь на известных routes.
Для HTML/page JSON соблюдать `Vary: X-Inertia`; персональные ответы не класть в общий
HTTP-кэш. Первоначально для нового renderer — private/no-store; публичный cache позже
после отдельной проверки пользовательских props, cookies и preview.

### Rollback

Переключить rollout в `off`, обновить config cache по штатному процессу; новые GET
возвращаются в legacy, незавершённые Inertia visits выполняют безопасный reload.
Не откатывать данные/схему БД: изменение темы их не требует. Старые views и assets
сохраняются; при deploy согласовать версии client/SSR и не удалять сразу старые
hashed chunks. Проверить stale-tab сценарий и исключить redirect loop. Различать
откат UI-флагом и откат release при дефекте общего backend-кода.

## 5. Page props и HTTP-контракты

### Данные

Предлагаемый общий контракт: `auth` (минимальный display user либо null), `navigation`,
`breadcrumbs`, `context` (тема/host), `flash`, `errors`, `meta`, `locale/timezone`.
Данные страницы отдельны: например `venues`, `filters`, `filterOptions`, `pagination`,
`abilities`. Не передавать весь User/Eloquent model, связи, Telegram raw_data,
init_data, секреты интеграций или закрытые контакты. Shared props не являются местом
для всех счётчиков, большого меню всех ролей и запросов всех страниц.

- URLs генерирует сервер; enum — стабильное value и подпись; дата — ISO со смещением
  и явная timezone для формы; деньги — существующее точное представление, не JS-float
  как источник расчёта. Nullable значения и пустые/ошибочные состояния явны.
- Permissions/visibility вычисляются прежними policies/use cases; `abilities` лишь
  управляет UI. Сервер повторно проверяет каждую мутацию и JSON-поиск.
- SQL из welcome/layout и значения composers переносятся в подготовку данных
  соответствующего экрана, с прежними scope, eager loading и лимитами. Проверить
  эквивалентность legacy; не исполнять параллельно одинаковые запросы для обоих UI.
- Списки ограничены/paginated; partial reload и deferred props — только для
  некритичных блоков. Сервер не доверяет присланному клиентом списку props для доступа.
- Пользовательский текст экранируется; HTML-контент допускается только после
  существующей серверной sanitization. SSR сериализует безопасно официальным адаптером.

### Формы и авторизация

| Клиент | Успех | Validation/auth failure |
| --- | --- | --- |
| Обычная legacy HTML-форма | Прежний redirect и flash | Прежние errors/old input |
| Inertia-форма на согласованном endpoint | Redirect к GET; актуальные props и flash | Redirect back + session errors, изолированные по форме; не JSON 422 |
| Существующий JSON/Telegram/predictive search | Существующий JSON-контракт | JSON 401/403/422 согласно endpoint, без перенаправления в HTML |

В `AuthController` и `RegisterRequest` Inertia-ветка должна предшествовать `ajax()`.
Проверить такие же условия в каждом переносимом FormRequest/controller и глобальном
exception handler. Нельзя просто убрать JSON-ветку: она нужна dark auth и Telegram.
Переиспользовать `AuthHandler`, `RegisterUserHandler` и
`SafeAuthenticationRedirectResolver`. В текущем коде регистрация сразу логинит
пользователя; не реализовывать по устаревшему описанию «после регистрации на login».

Session cookies, CSRF и canonical user middleware сохраняются, отдельные JWT/Sanctum
токены для этой темы не нужны. CSRF-токен берётся из штатного актуального механизма
Laravel/XSRF-cookie, не из навечно запомненного meta после login/logout. Проверить
ротацию сессии и токена; на 419 предложить восстановление/повторное открытие формы,
не автоматически повторять мутацию. Пароли не передавать обратно в props/old input.

Для формы обязательны pending/double-submit guard, errors, network failure, 403,
419, 429, 5xx. 409 предметного конфликта (занятый слот, изменившаяся игра) отличать
от Inertia location по заголовкам. Клиент не резервирует слот и не подтверждает
оплату/состав оптимистически. Транзакции, блокировки и idempotency — на сервере;
универсальный автоматический retry мутаций запрещён. Новые deadlock-риски этим
исследованием не установлены; конкурентное бронирование проверяется в 008.

## 6. SEO, первоначальный HTML и эксплуатация SSR

Рекомендация: **SSR для индексируемых публичных страниц до их включения**, не
обещание, что один Blade root с JSON равнозначен прежнему HTML. Для preview каталога
компонентов допустим CSR; SSR-compatible код писать с 004, первый SSR smoke — в 005
до переноса главной 006. Аккаунт — noindex, SSR необязателен; админка остаётся Blade.

Первый GET публичного URL должен содержать значимое содержимое, heading, ссылки,
canonical, title/description/OG и корректный 404/403 status ещё без JavaScript.
Metadata переиспользует `PageSeoResolver` там, где он уже применяется; canonical
не включает preview и служебные параметры. Нельзя иметь два конкурирующих источника
head при hydration. SSR не означает работоспособность каждой интерактивной формы
без JS: для таких действий нужен понятный noscript/fallback, отдельно проверенный.

Production: отдельный контролируемый Node SSR-процесс во внутренней сети, без
публичного порта, с healthcheck, restart, лимитом ресурсов, согласованной сборкой
и журналом ошибок без персональных props. Текущий одноразовый build-контейнер
этого не обеспечивает. В SSR нет новых бизнес-запросов: Laravel готовит props,
Node только рендерит Vue. Никаких глобальных mutable store между пользователями.
`window`, SDK, карты, observers и timers — только client lifecycle; случайные ID,
даты и viewport-разметка не должны давать hydration mismatch.

Inertia по умолчанию может незаметно перейти на CSR при сбое SSR. Это временная
деградация доступности, **не выполнение требования SEO**. Нужны проверка исходного
HTML, сигнал о деградации и операторский rollback; до релиза проверить остановку
SSR и восстановление. Не городить второй Blade-дизайн как постоянную SSR-заглушку.
Если отдельный runtime не принимается — вернуться к islands и явно пересогласовать
требование межстраничной навигации, а не молча отложить SEO на конец задачи.

## 7. Состояние, lifecycle и интеграции

- Route/query — источник фильтров, сортировки и pagination. Back/Forward восстанавливают
  URL и scroll; новый экран прокручивается вверх, фильтрация может сохранять scroll.
  Табы/модальные состояния, доступные по deep link, проектируются явно по экрану.
- Persistent layout сохраняет shell, но не весь дерево страниц через бесконтрольный
  KeepAlive. После перехода — title, focus на heading/основной контент, announcement;
  после модали — focus restore. Проверить browser history и hash anchors отдельно.
- Незавершённые обычные формы сохраняются в памяти при validation; remember/history
  допустимы только для белого списка несекретных полей с ключом формы/сущности.
  Пароли, контакты, договоры, init data не помещать в localStorage/history drafts.
  Переход на legacy предупредит о несохранённом вводе; безопасные booking-параметры
  можно восстановить через валидированный URL/session intent после auth. Серверный
  долгоживущий draft — отдельный сценарий, не скрытая функция темы.
- После logout/смены пользователя очистить shared state, drafts, realtime-подписки;
  проверить Back/BFCache на приватный экран. Использовать поддерживаемые выбранной
  версией Inertia очистку/защиту history и повторную авторизационную проверку.
- Карты, Three.js, сложные редакторы, realtime — lazy imports по реальной потребности.
  Predictive selector использует прежние scoped endpoints, debounce, отмену запросов,
  защиту от устаревшего ответа и повторную проверку выбранного ID.
- Каждый composable регистрирует cleanup для listeners, ResizeObserver,
  visualViewport, SDK, timers, requests и Echo channels. Один shell heartbeat,
  одна необходимая подписка на ресурс; page-scoped channel снимается при смене ID.
  После reconnect свежий snapshot, не предположение о доставке всех событий.
- Analytics adapter загружает счётчик один раз и отправляет один page hit после
  успешной навигации (включая согласованное Back/Forward), без дубля первого init.
  Не отправляет сырые auth hash/query, контакты или props; preview без production hits.
  Политику webvisor для чувствительных форм проверить отдельно, не копировать слепо.

## 8. Отложенный Telegram Mini App

Код подтверждает возможность сохранить нынешний flow через dark на **всех страницах
Telegram-контекста**, а не только `/telegram`. Это архитектурная гипотеза с тестовым
планом, не подтверждённая устройствами совместимость. Текущий session-флаг может
также влиять на обычную вкладку той же сессии: это presentation-состояние, не
достоверный детектор WebView. Preview его не переопределяет; отдельная browser
session позволяет сравнить UI. Точное tab-local разделение — будущая задача при
необходимости, не новая авторизационная эвристика по User-Agent.

Не менять JSON auth endpoint, серверную HMAC/возрастную проверку init_data, bootstrap
и серверное разрешение start_destination. Прямой browser Telegram-login не равен
Mini App: обычный web flow остаётся в выбранной web-теме. Старые query/hash и
canonical redirects проверять на сохранение назначения; не пересобирать подписанные
данные на клиенте и не принимать start_param как authorization.

С первого shell определить host adapter `browser/telegram`: ready, viewport/safe-area,
back/close и optional location capabilities. Browser implementation — безопасные
fallbacks; бизнес-компоненты не обращаются прямо к `window.Telegram`. Полная новая
Telegram UI-реализация не требуется сейчас, но интерфейс и stub должны позволять
подключить SDK без переписывания страниц. Отложенный SDK адаптер связывает BackButton
с закрытием верхней модали, затем history, затем разрешённым fallback/close; подписки
снимаются. Height использует dvh/visualViewport, CSS safe-area и SDK capabilities,
не фиксированную высоту по названию устройства.

Проверки перед включением переходного режима:

1. `/telegram` → signed auth → public/account destination остаётся dark, auth не
   повторяется на каждом GET; stale/invalid signature не создаёт сессию пользователя.
2. event, coordination, private rental, content и notification deep links:
   назначение сохраняется, чужое/удалённое/закрытое недоступно по прежним правилам.
3. SDK медленный/недоступен: hash fallback, понятная ошибка при отсутствии init data.
4. JSON venue create/edit/moderation и web Telegram-login сохраняют разные контрактные
   режимы; обе темы корректно реагируют на session/token rotation.
5. Admin, полный переход, Back, ориентация, safe-area и экранная клавиатура:
   реальные Telegram iOS/Android либо явно согласованный блокер релиза.

Если сохранение dark-контекста конфликтует с обязательным новым flow или требует
существенной переделки, остановить перенос и пересогласовать объём с пользователем.

## 9. Предлагаемые бюджеты и метод проверки

Это **целевые пороги**, не результаты замеров. KiB = 1024 bytes. Измерять production
build без HMR, отдельно cold entry и warm Inertia visit, без внешних analytics в
основном прогоне; отдельный прогон — с разрешёнными интеграциями.

| Показатель | Начальный бюджет |
| --- | --- |
| JS первого публичного экрана, сумма необходимых chunks | ≤ 250 KiB gzip, включая Vue/Inertia/icons |
| CSS первого экрана | ≤ 60 KiB gzip |
| Шрифты первого экрана | ≤ 120 KiB transfer, только нужные WOFF2 |
| Картинки первого viewport mobile | ≤ 400 KiB transfer; ниже fold lazy |
| Page props | shared ≤ 20 KiB, вся страница ≤ 100 KiB несжатого JSON |
| Первый mobile load | LCP ≤ 2.5 s, CLS ≤ 0.1; лабораторный TBT ≤ 200 ms |
| Warm переход (ответ backend) | p95 ≤ 500 ms на согласованном staging и фиксированных данных |
| Warm переход (клик → содержимое и доступность ввода) | p95 ≤ 1 s; pending feedback не позднее 100 ms |
| Регрессия lifecycle | После 20 туда-обратно переходов нет накопления подписок/timers; ровно один предусмотренный heartbeat |

Профиль mobile: Chrome, viewport 390×844, CPU slowdown 4×, download 1.6 Mbps,
upload 750 Kbps, latency 150 ms; записать версии, хост, ресурсы и dataset.
Cold: 5 прогонов, отчёт по медиане и худшему, не выдавать их за p95.
Warm: 20 измерений одного фиксированного flow, записать nearest-rank p95, backend
и client timing отдельно. API карт/3D не входят в начальный bundle и измеряются
отдельно после активации; исключения из бюджета только после согласования.
Реальный INP (цель ≤ 200 ms p75) требует полевых данных, TBT его не доказывает.
SSR: исходный HTML, отсутствие hydration warning, лог SSR failure и остановка/
восстановление сервиса. DevTools device Fit влияет на отображение виртуального
устройства, не на эти viewport-метрики. Тяжёлые прогоны — на соответствующем этапе,
короткими сериями, не в текущем исследовании.

## 10. Gate-проверки и следующий этап

| Когда | Обязательный результат |
| --- | --- |
| До приёмки 003 | Согласовать решения ниже; технические ограничения и непроверенные допущения остаются явными |
| Перед изменением приложения | Разрешённая интеграция main, повторная проверка source/route-контрактов и совместимости версий |
| Начало 004 | Малый foundation spike: root, request-scoped renderer, реестр, preview/off, отдельный entrypoint; контрактные тесты legacy/Inertia/JSON и последовательных контекстов |
| 004 | Tokens/UI-каталог, SSR-safe компоненты, TS typecheck; не перенос всех экранов |
| 005 | Shell, lifecycle, link modes, history/focus и первый SSR smoke на согласованном runtime |
| 006–010 | Для каждого flow матрица route/method/role/state, DTO, error/redirect контракты; пропорциональные тесты прав и данных, browser review и принятие |
| До 011/release | SSR/deploy/rollback, stale tab, приватность history/cache, Telegram и матрица браузеров, принятые performance budgets |

Риск-ориентированный набор тестов: preview не повышает права; admin/Telegram
всегда legacy; deep GET/Inertia GET согласованы; mutation выполняется один раз;
JSON auth остаётся JSON; Inertia validation возвращает errors через redirect;
нет утечки приватных props; отключение rollout не создаёт цикл и не теряет flash.
Не фиксировать косметическую разметку PHPUnit-тестами и не тестировать заново
весь домен только ради смены темы.

### Решения для пользователя

1. Принять Vue 3 + Inertia с SSR публичных страниц и соответствующий Node runtime?
   Без SSR альтернатива — islands с обычными межстраничными загрузками.
2. Утвердить `MSKBA Arena / mskba_arena` и локальный TypeScript новой темы?
3. Принять прежние URL, session-preview, explicit route registry и полные переходы
   в legacy; admin и весь Telegram-контекст — dark в первой версии?
4. Принять foundation spike в начале 004, SSR smoke в 005 и предложенные бюджеты?
5. Отдельно разрешить интеграцию актуального main перед реализацией (не выполнено)?

Принятие этого документа не означает, что адаптеры установлены, SSR работает или
совместимость устройств доказана. После согласования устойчивые решения перенести
в техническое раскрытие; коммит 003 — только после явной приёмки, без push/merge.

## Источники протокола

Официальная документация прочитана 2026-09-06; точные API сверить с выбранными
версиями при foundation spike:

- [Inertia 3: redirects, 303 и location visits](https://inertiajs.com/docs/v3/the-basics/redirects).
- [Inertia 3: validation через session и redirects](https://inertiajs.com/docs/v3/the-basics/validation).
- [Inertia 3: SSR, Node 22+, Vite и production runtime](https://inertiajs.com/docs/v3/advanced/server-side-rendering).