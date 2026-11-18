# Filament Backoffice — pilot on MSKBA

## Goal
Migrate the **administrator interface** to Filament 5 without rewriting Vue/Inertia/Blade portal screens or replacing the established system-role and canonical-user authorization.

Pilot at \`/backoffice\`: native Filament resource for \`AccountDashboardWidget\`; existing \`/admin/account-dashboard\` stays intact until dev acceptance. Both interfaces operate on the **same rows** in \`account_dashboard_widgets\`. No table migration or duplication is needed.

## Security
- Filament panel uses the existing Laravel \`web\` session; unauthenticated users go to the portal login. No independent Filament registration/identity store.
- \`User::canAccessPanel()\` verifies confirmed canonical admin status.
- Dashboard widget \`Gate::policy\` enforces CRUD; HTML/JS code editing and deletion remain superadmin-only. UI hidden controls do not replace server-side checks.
- Model save/delete broadcast invalidation to Reverb, so editing via either admin interface immediately refreshes authorized user dashboards.
- Existing iframe sandbox for user-facing HTML/JS is not weakened.

## Delivery blocker to resolve before dev rollout
CI currently refuses deploys that modify composer.lock (and the staging VDS has only about 2 GiB RAM). The feature-only dependency workflow resolves a **real** reproducible \`composer.lock\` in GitHub Actions; it must never fabricate package versions. Before the pilot reaches \`dev\`, prepare safe server-side dependency activation with a verified vendor build + rollback/backup and full CI. The default dev CI and existing admin remain untouched until that release procedure is proven.

Filament installer docs: https://filamentphp.com/docs/5.x/introduction/installation


## Dev release (Composer dependency changes)

The Composer lock was generated and verified on GitHub Actions; the temporary resolver workflow is deleted before merge. The existing dev GitHub Actions workflow now:

1. Detects changes to \`composer.json\` / \`composer.lock\` across the pushed commit range.
2. Runs the full project test suite and frontend build.
3. For a dependency change only, uploads a SHA256-identified, **CI-installed** \`vendor\` archive with the matching \`composer.lock\` (short-lived artifact).
4. Stages that archive on the dev VDS. Before changing the live checkout, verifies archive hash and compares the staged lock against the verified commit.
5. Switches dependency tree and source with previous source SHA, previous vendor and Vite manifest available for rollback.
6. Runs package discovery and \`filament:assets\`, then existing migrations/seeds, frontend asset activation and Docker service refresh.
7. Tests guest HTTP reachability of \`/backoffice\` and the existing queue health guard before clearing backup artifacts.
8. On failure, restores previous source tree, previous PHP dependencies and previous Vite manifest before restarting services. Never builds or resolves Composer dependencies on VDS.

The old \`/admin/account-dashboard\` editor remains available as a fallback; the navigation points to Filament only after the pilot is merged and deployed. No production changes should be made until a separate release review.

## Acceptance
- Confirmed admin visits \`/backoffice/account-dashboard-widgets\` using existing login.
- Ordinary user and unconfirmed admin cannot open \`/backoffice\`.
- Create / edit ad, info, rules and HTML (superadmin only), validate existing \`/account\` live refresh via Reverb.
- The user-facing widget data remain the same because Filament writes the original model/table.
- Admin text, defaults and navigation use Filament's native layout with Russian locale.

## Кадрирование фотографий площадки

- Реализация: `PhotosRelationManager` открывает Filament action modal непосредственно по нажатию на `ImageColumn`; внутри Alpine-интерфейс с touch/pointer событиями, восемью ручками и pinch gesture. Скрипт и стили подключаются один раз через `BackofficePanelProvider`.
- Данные: `media.original_path` + `media.original_disk` (исходный JPEG/PNG/WebP в приватном `local`-хранилище), `media.crop_draft` (выделение в нормализованных 0–1 координатах) и `media.crop_applied` (последний применённый кадр). `media.path` продолжает указывать на текущую публичную WebP-версию. Формат выбран для повторной обрезки без накопления потерь.
- Загрузка через `VenueGalleryManager` по-прежнему создаёт оптимизированную WebP-версию с максимальной стороной 500 px, но также сохраняет нетронутые байты оригинала в приватном `local`-хранилище. Доступ к исходнику в модальном окне идёт через авторизованный маршрут `backoffice.venue-photos.original` с запретом HTTP-кэширования; публичная раздача исходника не используется. При удалении медиа очищаются и оригинал, и публичный файл. Для исторических записей без `original_path` используется существующий WebP; это не восстанавливает детали, утраченные при старой загрузке.
- Сохранение черновика вызывается через публичный Livewire-метод `saveVenuePhotoCropDraft` relation manager-а после завершения жеста. Применение — `applyVenuePhotoCrop`, вызывающий `VenuePhotoCropService` и `WebpImageNormalizer::crop` с учётом EXIF ориентации. Сервер ограничивает рамку границами изображения, проверяет права на ресурс и принадлежность фото площадке, защищает строки транзакцией и создаёт новый путь WebP перед переключением `media.path`. Неприменённый черновик не влияет на URL и изображение посетителя.
- Ограничение: редактор доступен администраторам Filament для опубликованного набора фото; пользовательский moderation/revision flow по-прежнему отдельно управляет публикацией фотографий. Старые изображения редактируются на основе доступной сохранённой WebP-версии.
- Проверки: серверные регрессии для отдельного исходника, сохранения черновика, повторного кадрирования, legacy-фотографии и неверного media-id. Мобильное удобство требуется отдельно проверить в Safari iOS и Chrome Android (захват сторон/углов, pinch, закрытие/повторное открытие).

## Production deployment: Filament published assets (2026-11-18)

Filament's package CSS and JS are not part of the MSKBA Vite build and are excluded from Git by `.gitignore`. The application does not register `filament:upgrade` under Composer `post-autoload-dump`, so production `.github/workflows/deploy.yml` explicitly runs `php artisan filament:assets --no-interaction` after Composer installation. It fails the deploy before service recreation if the published panel `app.css` or `app.js` is absent or empty. This safeguards `/backoffice` after releases and dependency updates. A successful HTTP / homepage smoke check alone does **not** establish that Filament has its CSS/JS; verify those paths and the rendered panel if a visual regression is reported. See [task 326](../tasks/326/description.md).

## Навигация и главная страница backoffice (2026-11-18)

См. [задачу 328](../tasks/328/description.md). Группы бокового меню заданы нативными `NavigationGroup` с `collapsed()`, содержимое распределяется через `$navigationGroup` у каждого ресурса и страницы. Пункт «Дашборд» остаётся на `/backoffice`; `BackofficeDashboard` отображает `NavigationOverview`, который читает `Filament::getNavigation()` и использует только реально видимые и доступные ссылки. Права, маршруты и hidden-статусы ресурсов не переопределяются. `navigation-state.blade.php` синхронизирует состояние локального sidebar Filament при первой загрузке и переходах Livewire: закрыты все группы, кроме активной; пользователь может раскрывать и закрывать их вручную на текущей странице. Карточки используют стандартные Filament Section и адаптивный CSS `public/css/backoffice-dashboard.css`.

### UI-гайдлайн главного дашборда (обновление 18.11.2026)

Карточки доступных разделов используют стандартные Filament Section, их естественная высота не выравнивается до соседней. CSS multi-column masonry (`column-count` 3/2/1 в зависимости от ширины) обеспечивает плотное вертикальное расположение; порядок чтения в таком layout — сверху вниз по колонке. Разделы получают единые Heroicons Outline, заголовки sidebar — CSS-mask с тем же SVG, без штатного `NavigationGroup::icon()`, чтобы не скрывать иконки дочерних элементов. Счётчик ссылок строится по уже разрешённым пунктам `Filament::getNavigation()` и не раскрывает скрытые ресурсы. Стили локальны для `mskba-bo-dashboard-*`; интерактивный focus-visible применяется только к ссылкам. Подробности: [task 328](../tasks/328/description.md).

### Подраздел «Интеграции и API» на дашборде (18.11.2026)

Виджет \`NavigationOverview\` визуально отделяет ссылочную секцию «Интеграции и API» в группе «Сайт и дизайн» тонкой линией, но всё ещё использует **только разрешённые** пункты \`Filament::getNavigation()\`. Ссылка на интеграции площадок ведёт к существующему реестру площадок (вкладка «Интеграции» доступна в редакторе конкретной площадки); дополнительно проверяется \`backoffice.venue.integrations.view\`. DEV Explorer/Swagger/OpenAPI/GraphQL и доступ агента остаются под прежними environment+RBAC gates, но сгруппированы в «Сайт и дизайн». На production показываются только маркированные DEV-ярлыки к Swagger/OpenAPI/GraphQL при соответствующих правах, с переходом на \`dev.mskba.ru\` и отдельной авторизацией: **production Explorer не включается**. См. [задачу 328](../tasks/328/description.md).
