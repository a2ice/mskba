# MSKBA Hero Slider · Page Builder component

## Status

First integrated implementation in feature branch based on \`dev\` (2026-10-16).
No change to the active Blade homepage is made by installing this feature.
No main/production publication is part of this task.

## Integration

- \`Дизайн → Компоненты → Hero Slider MSKBA\` edits a seeded, **unpublished** \`hero-slider\` record.
- Fill/reorder up to **6 slides**. Each has eyebrow, title (newlines accepted),
  accent line, description, CTA text/relative URL, five optional highlights,
  desktop arena background, optional separate mobile crop and near/far PNG/WebP players.
- Choose fade / slide-up / slide-left / zoom separately for the background,
  players, text, CTA and highlights. The advanced timeline sets independent
  appearance delays for all six groups (0–5000 ms). Enter
  optional desktop/mobile player X coordinates in percent, desktop widths in percent.
- Set arrows, dots, and optional autoplay interval (4–30 seconds).
  Autoplay defaults **off**; pauses on hover/focus, on a hidden tab,
  outside the viewport and for reduced motion.
- Save the draft; publishing the component copies validated slides/options to
  their own published snapshots and writes a revision.
- Insert the component into any Page Builder page's HTML:

  \`\`\`html
  <mskba-component name="hero-slider"></mskba-component>
  \`\`\`

- In \`Контент → Страницы → Главная\`, save the page draft and use
  **Предпросмотр**; do **not** publish \`/\` before the owner accepts the first slide.
- An unpublished component renders in page draft preview, but never on a
  published page. Published pages use only published slide settings.

## Layout and media

- Desktop: dark cinematic arena, white/orange text left, two optional alpha
  images of players, five highlights in the **same action row as the CTA**,
  right-aligned, with no backing plate.
- Compact-height desktop: preserve the same flex action row, tighten spacing
  and title typography where needed to avoid overlapping CTA and highlights.
- Tablet: CTA remains on the left, while the remaining width contains a clipped
  seamless highlight marquee. It pauses on hover/focus; the repeated visual
  group is inert and hidden from assistive technology. Reduced-motion users
  get a stationary horizontally scrollable rail instead of automatic motion.
- Stage height is explicitly sized (not content-driven) because the background and
  transparent player images are absolutely positioned. After the CTA and highlights
  share one row, desktop uses `clamp(450px, 35cqw, 530px)`, short desktop
  `clamp(410px, 32cqw, 480px)`, tablet `clamp(440px, 62cqw, 540px)`.
  Mobile height is intentionally unchanged to preserve room for composition
  and the existing fixed bottom navigation. Recheck when final transparent player
  imagery is integrated.
- Navigation arrows: first-party centered SVG chevrons, not font glyphs.
- Mobile: crop/recompose the scene, hide highlights and carousel arrows,
  use swipe and dots, reserve vertical space for the project's existing
  fixed mobile bottom navigation. **Do not create another nav bar inside hero.**
- Both characters are separate uploads. The court, stand lights, hoop and
  arena remain a single background image. If no asset is uploaded yet,
  use the existing site's \`/images/bg-indoor.png\` as a **temporary placeholder**.
- Use the public disk, directory \`page-builder/hero\`, with bounded PNG/JPEG/WebP/AVIF
  uploads for backgrounds and transparent PNG/WebP for players. Optimized final
  transparent images will be produced as a separate acceptance task.
- Inline SVG icon markup comes from the trusted PHP view, not from user HTML.
  Inline styles are calculated solely from server-validated numbers.

## Safety / boundaries

- Never allow PHP, Blade, SVG/JS, \`<script>\` or arbitrary page CSS in a stored slide.
- Links must be internal paths or anchor fragments. Upload paths are restricted
  to \`page-builder/hero\` with safe extensions and filenames; no remote URL.
- \`PageBuilderRenderer\` resolves the trusted \`hero-slider\` branch before
  sanitizing page HTML and substitutes sanitized only where applicable.
- The hero JS and CSS are first-party Vite assets imported by existing
  \`resources/js/page-builder.js\` / \`resources/css/page-builder.css\`.
- The main \`PageBuilderMarkup\` sanitization is **unchanged**.
- Generic HTML components and \`games-list\` remain separate and functional.

## Checks

Automated:
- \`php artisan test --filter=PageBuilderHeroSliderTest\`
- \`php artisan test --filter=PageBuilderFeatureTest\`
- \`npm run build\`
- GitHub CI

Browser acceptance at **1440×900, 1280×720, 768×1024, 390×844, 360×640**:
- edit/reorder/save and publish a slider configuration in Filament;
- page **draft preview** shows configured slides without publishing the homepage;
- switch arrows/dots/swipe, keyboard left/right and loop;
- switch slides repeatedly, including after navigating back from elsewhere;
- inspect title/CTA/highlights/pagination, no overlap with the mobile fixed bar;
- check reduced-motion and reduced-transparency settings;
- disable images or use placeholders; no broken UI and no duplicate player layers;
- edit a published slide's draft and confirm public page is unchanged;
- verify arbitrary JS, remote media and unsafe links cannot be saved/published.

**Deployment caution:** Merge this feature to dev only after CI and Filament QA.
Publish neither the component nor \`/\` without explicit approval from the owner.

QA on DEV: 1440×900, 1280×720, 768×1024, 390×844, 360×640; confirm CTA/highlights are co-linear, tablet marquee clips to available width without horizontal page overflow, reduced-motion disables marquee, arrow chevrons are visually centered in their circular buttons, and mobile has no highlights.


## Hero Slider — universal layered slide format (v2)

The existing \`page_builder_components\` record with slug \`hero-slider\` already
contains the \`draft_slides\`, \`published_slides\`, \`draft_options\` and
\`published_options\` **JSON columns**. No new per-slide or per-element tables
are necessary. A non-destructive migration adds \`layout: legacy\` to existing
slide JSON; published snapshots and revision history are preserved.

New slides use \`layout: layers\`. Each slide has a human-readable name,
enabled flag, optional responsive background, optional scrim, audience
conditions and an ordered \`elements\` array. Each element has:

- \`type\`: \`heading\`, \`text\`, \`image\` or \`button\`; \`name\` (editor only).
- \`content\` or public-disk \`image\`, optional image \`alt\` and internal \`url\`.
- \`class_name\`: up to 3 safe CSS classes, no arbitrary CSS or JavaScript.
- \`x/y/width\` and \`mobile_x/mobile_y/mobile_width\`: percentages of slider
  canvas. \`z_index\` ranges 2–7; \`align\` is left/center/right.
- \`animation\` preset, \`delay_ms\` (0–5000) and \`duration_ms\` (100–3000).
  Every element owns its timeline independently. Reduced-motion disables it.

Limits: 6 slides, 16 elements per universal slide, bounded field lengths/media
types, uploaded media only under \`page-builder/hero/\`, internal links only.
Both legacy and layered slides can coexist in a single slider; previous
legacy-specific editor controls are shown only for \`layout: legacy\`. The
default for a newly added slide is the generic mode.

Audience conditions are evaluated **server-side on each request** before the
slider HTML is returned: authentication \`all|guest|authenticated\`, confirmation
\`any|confirmed|unconfirmed\`, optional system roles and active participation
roles. A slide with no matching audience is omitted (and all its content, image
URLs and animation data never reach the client). A slider without matching
slides renders empty. Editor preview intentionally bypasses targeting so staff
can inspect all slide variations, but disabled slides stay hidden. Audience
filtering is not merely browser CSS or JavaScript hiding.

The JSON keys \`audience.tags_any\` and \`audience.tags_none\` are **reserved**.
Their nonempty use is rejected by validation until user tagging and its shared
matching service exist; legacy imported records with these fields fail closed.
No simulated or implicit tag matches are performed.

Existing Page Builder draft/publish/revision mechanics apply unchanged. New
slides and editing older slides do not affect published output until
\`Сохранить и опубликовать\` or the separate Publish action is performed.


## Acceptance: recreating the MSKBA hero in universal mode

When recreating the old design as typed layers, assign the following opt-in CSS
preset classes: `slider-eyebrow`, `slider-heading`, `slider-accent`,
`slider-description`, `slider-cta`. These are first-party
container-relative typographic styles and are isolated to generic slides;
the previously published legacy slides are not restyled.

New layer type `links` (`Группа ссылок · хайлайты`) is a reorderable
list of 1–8 typed links. Each has label, internal URL, and an icon selected from
`court/trophy/users/video/chart` or a custom uploaded raster image. Reuses
the trusted existing carousel highlight CSS and responsive behavior: row on
desktop; scrolling highlight rail on tablet; hidden on mobile. As with
legacy highlights, duplicate marquee link groups are inert and hidden from
accessibility APIs. All URLs, upload paths, alt text and classes are validated
server-side; inline SVG icons are first-party Blade markup.

Example first slide at desktop 1280 × 450: eyebrow `(6%, 16%, 60%)`,
heading `(6%, 24%, 75%)`, accent `(6%, 35%, 75%)`,
description `(6%, 51%, 42%)`, CTA `(6%, 69%, 22%)`,
links group `(43%, 68%, 53%)`. Final alignment and mobile values must
be adjusted after visual QA on the author's actual assets.

The `show_arrow` toggle on button layers defaults to on (matching the
legacy CTA); it may be switched off for other button types.
The global arrows/dots appear only when **at least two audience-visible slides**
are enabled. This is intentional; check both legacy slide toggles and audience
filters before diagnosing a missing navigation control.


### Responsive layer positioning (v3)

Layer coordinates now support three **independent layout bands**, measured by
the actual slider container width (CSS container queries), not screen height:

- Desktop: width greater than 1050 px — `x/y/width`.
- Tablet: width from 641 to 1050 px — `tablet_x/tablet_y/tablet_width`.
- Mobile: width up to 640 px — `mobile_x/mobile_y/mobile_width`.

Every tablet field is nullable in the Filament editor. An unset tablet value
inherits the corresponding desktop coordinate, individually. Legacy JSON,
published slide snapshots and saved draft layers are not migrated, reset or
changed. Values are server-validated and emitted as safe, bounded CSS custom
properties. Generic tablet positioning does not touch the legacy slider.
At tablet widths, the existing highlights marquee remains horizontally
scrollable; at mobile widths highlights remain hidden, matching the legacy
presentation.

Suggested **starting** tablet composition for the classic MSKBA hero
(eyebrow/heading/accent/description/CTA/highlights): X=6/6/6/6/6/42,
Y=12/20/29/45/69/69, width=88/92/92/78/30/54 (all percentages).
Suggested mobile composition at 360–390 px:
X=6 for all visible text/button layers, Y=9/16/31/49/68,
width=88/92/92/88/55. The highlight layer is hidden on mobile.
These are initial authoring values, not visual-acceptance guarantees.
Test 1024, 768, 641, 640, 390 and 360 px in an actual browser before final
acceptance, especially heading wrapping and vertical spacing.


## Retirement of old fixed-layout editor on DEV

The migration \`2026_10_17_002000_convert_dev_legacy_hero_slider_slides_to_layers\`
preserves the complete pre-conversion draft/published JSON in a
\`page_builder_revisions\` backup before converting fixed MSKBA slides to
typed layers. The converter retains titles, CTA, labels, internal URLs, icon
groups, images (including optional players), animation delays, audience and
enabled state. A built-in \`indoor\` background preset preserves the historical
arena image when the legacy slide lacked its own uploaded background.

Once this migration succeeds in staging, the Filament editing form no longer
offers the \`legacy\` format or displays specialized controls for a "far/near
player". Existing universal settings remain intact. Backend legacy rendering
is temporarily retained solely as a rollback/archival compatibility path;
remove it only after independent browser verification of old slide revisions.
This guarantees that older revision snapshots remain renderable during recovery.
No changes to production DB or main branch are permitted.
