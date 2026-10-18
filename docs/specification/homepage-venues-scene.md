# MSKBA Homepage · Venues Scene (PR1: responsive static layout)

Status: DEV-only implementation for visual acceptance, 2026-10-19.
No Page Builder database records are modified, and production/main are unaffected.

## Layout / ownership

The published Page Builder homepage stays intact. Only when `APP_ENV=staging`,
the request is the published root `/`, and its rendered HTML actually contains
`data-mskba-hero-slider`, the Page Builder view wraps the existing HTML in
`.mskba-home-story` and appends the trusted `venues-scene.blade.php` partial.

`.mskba-home-story` is the *natural-height* canvas. The artwork is an absolutely
positioned background from its **top edge immediately below the site header**
to the end of the Venues content. The **Venues content stays in normal flow**
and determines the height, avoiding absolute-position height collapse.

The existing boxed Hero markup, slider internals, navigation, audience
visibility and independent scrolling ball are not modified in PR1.

## Artwork

Use the original assets already committed to DEV, in this exact back-to-front
order under `public/images/court_day/homepage/`:

1. `layer-1-backstage.png` (sky/backstage)
2. `layer-2-fense.png` (fence; filename intentionally retained)
3. `layer-3-hoop.png` (backboard and hoop)
4. `layer-4-player.png` (shooter)

All layers use a common full-frame canvas and the same `object-fit: cover`
registration. On small viewports, `object-position` favors the player.
PR1 has no scroll/parallax movement yet; the CSS custom property
`--venues-layer-offset-y` is reserved for PR2.

The content uses the design brief: small orange eyebrow, black `h2`,
very large low-contrast ghost heading, description, CTA to `/venues`,
and four supporting features. Feature text is descriptive, not clickable
until corresponding product destinations are verified.

## Breakpoints

- Desktop: 1051px and up; readable right-side copy, artwork on left.
- Tablet: 641–1050px; copy column adapted and features in 2 columns.
- Mobile: 640px and below; keep extra photo space below Hero, then
  readable content, CTA and 2×2 features. Height is content-driven.
- Reduced motion: no layer transforms if OS setting requests it.

Hero scroll/rotation remains untouched. No additional `fixed`/`sticky`
elements or JavaScript listeners were introduced.

## Integration files

- `resources/views/page-builder/components/venues-scene.blade.php`
- `resources/css/page-builder-venues-scene.css`
- `resources/views/page-builder/show.blade.php`
- `app/Modules/Content/Presentation/Http/PageBuilderController.php`
- `resources/css/page-builder.css`
- `tests/Feature/PageBuilderVenuesSceneTest.php`

## Visual QA required on DEV before PR2

Check viewport widths 360, 390, 768, 1024, 1280, 1440, 1920:
- Sky behind Hero immediately below site header; no separate white band.
- Four PNGs register correctly; fence/hoop/player align to source canvas.
- Player does not obscure the heading and CTA.
- `h2`, copy and all features remain legible and keyboard accessible.
- No horizontal overflow and no collapse/giant empty gap at breakpoints.
- Existing ball completes Hero unchanged; do not hand off in PR1.
- Verify current theme `court_day` and alternate theme backgrounds.
- Check heavy PNG load time in iPhone Safari before optimization in PR4.

## Following work (not in PR1)

- PR2: scroll-driven parallax, ghost heading arriving from right, features
  appearing at 50% scene progress. Respect reduced-motion.
- PR3: one physical ball handoff from Hero to the player release point.
  Scene geometry must be accepted before setting endpoints.
- PR4: typed settings in Filament, media optimization (WebP/AVIF
  retaining alpha), production opt-in, and removal of the temporary
  `scrollTop` debug indicator after user acceptance.

Production enablement must be explicit through an approved Page Builder
publication, *never* an accidental effect of merging this staging prototype.
