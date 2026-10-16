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
