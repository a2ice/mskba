# MSKBA Page Builder (first implementation)

## Scope (2026-10-16)

The builder uses the existing Filament v5 backoffice, design palettes, and
Laravel routes. It does NOT replace the legacy welcome page automatically.

- **Content → Pages**: URL, page-specific HTML/CSS, SEO, preview and publish.
- **Design → Templates**: reusable HTML/CSS with \`[[page_content]]\` placeholder.
- **Design → Components**: reusable HTML/CSS, inserted with
  \`<mskba-component name="hero-banner"></mskba-component>\`.
- **Dynamic sources**: built-in \`games-list\` renderer, e.g.
  \`<mskba-component name="games-list" source="my_games" limit="5"></mskba-component>\`.
  The backend renders a shimmer skeleton, the browser fetches the typed data
  (limit 1-12), replaces content with safe DOM text and relative links.
  Available data: \`upcoming_games\`, \`my_games\`, \`featured_teams\`.
- **Permissions**: \`backoffice.page_builder.view/edit/publish\` require
  \`backoffice.access\`. Only superadmins have all three by default.
- **Publish**: edit/save draft, then explicitly publish with confirmation.
  The editor's `path` is a draft URL; `published_path` remains fixed until publication.
  Existing public URLs are backfilled and cannot be claimed by another page.
  Published MSKBA app pages emit a canonical link. 
  Published HTML, CSS, SEO and template selection are separate snapshot fields.
  Publication records a revision. Restore changes only the *draft*; to roll back
  the public view, restore draft, save, and publish again.
  Publishing a page with a missing, unpublished or malformed component
  placeholder is rejected before the public snapshot changes; the editor is
  told to publish its components first. Dynamic `games-list` requires an
  allowed source. The preview may still show component drafts.
- **Preview**: authenticated backoffice route; noindex/no-store.
- **Routes**: `/` uses the Page Builder once published; its existing legacy
  fallback remains available. New single-segment paths use the existing CMS
  fallback. For a matching existing, named, static and public GET/HEAD
  Laravel route (e.g. `/events`), publication gives CMS the priority.
  Filament shows the original Laravel name and handler, plus actions to
  disable/re-enable the CMS override without deployment or rewriting route files.
  This works with `route:cache`; old named routes are retained.
- **SEO**: published title, description and route-aware canonical URL.
- **Design themes**: CSS can reference the existing CSS tokens; builder rules
  are prefixed with \`.mskba-page-builder\` to prevent styling surrounding UI.

## Shared content width and full-width sections

The outer `.mskba-page-builder` is **fluid and spacing-neutral**:
100% wide, without max-width, margin, padding, or gap. The default
`.mskba-builder-section` template wrapper also adds no gap/padding.
Neither wrapper is a design/layout container: each section controls its
own top/bottom spacing, so the first full-width hero can sit flush against
the site header or context bar.
This allows a page to mix full-bleed sections and centered content.
The existing `.container` from the `mskba_app` design system is the
**single source of truth** for header, breadcrumbs and boxed Page Builder
content (1280px max, 32px/24px/16px responsive side gutters).

Author boxed sections in the page or template editor:

```html
<div class="container">
  <mskba-component name="hero-slider"></mskba-component>
</div>
```

Author full-width sections without this wrapper:

```html
<section class="events-hero">
  <h1>Мероприятия</h1>
</section>
<div class="container">
  <h2>Каталог</h2>
</div>
```

The builder HTML allowlist accepts `section`, `div` and `class`; editors
must still use scoped CSS validation, never arbitrary JS/PHP.
A one-time, idempotent **data migration** wraps pre-existing draft and
published page HTML in explicit `.container` elements (unless already
boxed). This preserves the current DEV layout, including the home hero
and /events, while allowing new CMS pages to choose widths per section.
Templates, components, route ownership and past revisions are untouched.
Migration rollback does not remove editor-controlled HTML wrappers.

### Spacing utilities for section authors

Page Builder includes a scoped, dependency-free subset of Bootstrap 5 spacing
utilities. Classes apply only within `.mskba-page-builder`, not the rest of
the site's existing design system:

- `m-*`, `mt-*`, `mb-*`, `ml-*`, `mr-*`, `ms-*`, `me-*`, `mx-*`, `my-*`
- `p-*`, `pt-*`, `pb-*`, `pl-*`, `pr-*`, `ps-*`, `pe-*`, `px-*`, `py-*`
- `gap-*` for flex/grid layouts, plus `mx-auto` and `my-auto`

Each numeric suffix uses the Bootstrap 5 scale: `0 = 0`, `1 = 4px`,
`2 = 8px`, `3 = 16px`, `4 = 24px`, `5 = 48px` at a 16px root size.
`s/e` are logical inline start/end; `x/y` are horizontal/vertical.
These are simple CSS classes, not the Bootstrap or Tailwind frameworks.

Example mixing full-width backgrounds with boxed content:

```html
<section class="py-5">
  <div class="container">
    <h2 class="mb-3">Мероприятия</h2>
    <p class="mt-2">Каталог</p>
  </div>
</section>
<section class="py-0">
  <mskba-component name="hero-slider"></mskba-component>
</section>
```

Place spacing classes on the desired section/wrapper. The Page Builder
root and base template never introduce default space of their own.

### Theme-aware background utilities

All background classes are available inside `.mskba-page-builder`, for both
`arena_night` (dark) and `court_day` (light), including customized published
Design Themes. They use the live theme's CSS color tokens and update when the
selected theme changes, without copying HEX values into builder pages.

Families: `blue`, `grey`, `orange`, `green`, `red`, `yellow`.
Each family has **9** classes: 3 solid fills and all 6 directed gradients
between its light, normal and dark shades:

- Solid: `bg-lightblue`, `bg-blue`, `bg-darkblue`.
- Gradient: `bg-grad-lightblue-blue`, `bg-grad-blue-lightblue`,
  `bg-grad-blue-darkblue`, `bg-grad-darkblue-blue`,
  `bg-grad-lightblue-darkblue`, `bg-grad-darkblue-lightblue`.

Substitute `blue` with any other family, e.g. `bg-lightgrey`,
`bg-orange`, `bg-grad-darkgreen-lightgreen`. All gradients run from
top-left to bottom-right (135°); reversing their color names reverses the
color order. Additionally, `bg-page`, `bg-surface`,
`bg-surface-raised`, `bg-field`, `bg-accent`, and `bg-transparent`
map directly to the current theme.

```html
<section class="bg-grad-lightblue-darkblue py-5">
  <div class="container">
    <h2 class="mb-3">Площадки Москвы</h2>
  </div>
</section>
<section class="bg-lightgrey py-4">
  <div class="container">...</div>
</section>
```

Utilities set **only the background**. Text and icon colors are not changed
automatically; check contrast in both light and dark themes. Page-specific
local CSS can override the utilities with a more specific selector.

## Главная — раскрытие карты площадок (DEV, 2026-10-26)

В существующем опубликованном Page Builder компоненте `venues-map`
сама Яндекс Карта всегда занимает всю ширину белого закруглённого блока.
Кнопка **«Открыть карту»** на главной теперь раскрывает карту **на месте**,
не переводя пользователя в каталог площадок: левый текстовый слой
`.mskba-venues-map__copy` и полупрозрачный `.mskba-venues-map__scrim`
синхронно выезжают за левую границу clipping-контейнера.
Фильтры и маркеры остаются доступны; в верхнем левом углу появляется
44×44 кнопка `←` для восстановления исходного состояния. Кнопка
`Escape` также закрывает раскрытие. Плавный переход выключен при
`prefers-reduced-motion`; `inert` исключает скрытые ссылки из таб-навигации.
При отсутствии JS исходная ссылка `/venues` остаётся рабочей.

JS и CSS разработаны в `resources/page-builder/venues-map.{js,css}`.
HTML из Filament **не переписывается**: кнопка возврата создаётся JS,
а исходная CTA перехватывается после загрузки скрипта.
Перед публикацией сохранены точные копии двух ранее действовавших
файлов в `resources/page-builder/versions/venues-map-pre-expand-20261026.*`.
Релизная команда `mskba:venues-map:expand-toggle --check|--apply`
предназначена **только для staging**; проверяет под блокировкой БД,
что draft/published HTML и JS/CSS не отличаются от сохранённого
baseline. Публикует через стандартные CSS-ревизии и JS-аудит,
сохраняя слоты `previous_css` и `previous_js` для отката.
Однократное исполнение встроено в `dev` CI/CD с серверным marker-файлом:
последующие редакторские правки Filament автоматически не перезаписываются.

## Editor layout in Filament 5

On every existing Page Builder editor (Pages, Templates and Components), the form
footer includes **«Сохранить и опубликовать»** alongside the regular Save
button. The combined action confirms publication, validates/dehydrates the
current form through Filament, saves the draft without redirect, then publishes
via `PageBuilderPublisher`. It requires both edit and publish permissions,
even for direct Livewire method calls. If publication fails, the saved draft
remains, but the previous public snapshot remains intact. New records are first
created normally; publishing is available in the edit form. Existing standalone
Save and Publish actions remain available.

Page, template and generic component edit forms use a single outer column.
Metadata is followed by a full-width code editor, with the placeholder guide
in a collapsible section **below** HTML/CSS instead of consuming a third of the
code editing area. The Hero Slider's structured component form retains its
internal multi-column fields but occupies the full available outer width.
The layout applies equally to create and edit screens; it does not change
stored content, publishing, preview URLs or validation.

## Security rules

No arbitrary PHP, Blade, SQL, JavaScript, iframe or inline event attributes
are accepted from editor code. Symfony HtmlSanitizer restricts the HTML
allowlist. CSS accepts only inert, whitelisted declarations and strictly scoped
selectors; no imports, URL requests or global rules. Dynamic sources are PHP
allowlist entries, never user-provided SQL/model names. Private \`my_games\`
requires an authenticated caller and is not cached. All JSON reads return
\`Cache-Control: private, no-store\`. Query limits cap at 12; use only the
public subset of event and team fields in anonymous responses.

## Important limitations / next iterations

- The first release is **code-first**, not WYSIWYG. Add the visual editor
  against the same structured component model later.
- Complex animated hero slider, media management, component-specific setting
  panels, and embedded Vue/JS modules are separate approved implementation
  increments. Do not allow raw JS code in page records as a shortcut.
- Component nesting and arbitrary CSS at-rules are intentionally unavailable.
- Protected system roots, guarded routes (auth/signed/throttle/permissions),
  parameterized entity pages and create/edit forms cannot be overridden yet.
  Later stages use typed components that preserve Laravel business rules.
- The CMS version of `/events` will not automatically contain its legacy
  filters or event list: an `events-catalog` component is a separate task.
- Existing dashboard widgets remain in their separate subsystem until a
  dedicated migration/adapter is designed; do not silently replace them.
- Keep homepage unpublished until public design and hero-slide layers
  receive explicit approval.

## CMS Route Override — DEV acceptance

1. Save `/events` draft: old `events.index` remains active; Filament displays
   its Laravel route and `EventController::index`.
2. Publish: `/events` displays CMS output, canonical and page SEO.
3. Disable CMS: original Laravel controller is active immediately. Enable CMS:
   previously published CMS output returns; both actions audited.
4. Verify `/events/create`, `/events/{event}`, POST `/events`, auth/account/API
   routes cannot be intercepted.
5. New `/about-cms` works via fallback; draft edits never change public HTML.
6. Run route cache, full CI and browser acceptance on DEV; not production.

## Site Media Library

Новая медиабиблиотека для изображений оформления, логические папки и выбор UUID-ассетов в Hero Slider описаны в [Site Media Library](site-media-library.md). Поля FileUpload и старые JSON остаются совместимыми; импорт выполняется явной CLI-командой, без автоматической публикации страниц.
