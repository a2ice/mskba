# Homepage Venues Scene — PR 1 (DEV layout)

## Scope and activation

The first iteration lays out the **static**, responsive Venues introduction immediately
under the current Page Builder Hero Slider. The four already committed PNG assets
are composited in a common visual backdrop **starting at the very top of Hero**
(under the site header) and continuing into the Venues section.

This staging-only rollout deliberately **does not mutate Page Builder draft or
published HTML/CSS in the database**. The trusted Blade wrapper is rendered by
`resources/views/page-builder/show.blade.php` only when ALL conditions hold:

- Laravel environment is `staging`;
- the route is the published homepage `/`, not an editor preview;
- the rendered page actually contains `data-mskba-hero-slider` and
  `mskba-hero-scene` (the existing opt-in Ball/Hero composition).

Other Page Builder pages and production are unaffected; `main` is not touched.
To turn off the staged section, revert the wrapper branch or disable the
staging-specific Blade guard (no DB rollback, no content publishing).

## Structure

`mskba-home-story` creates a normal document-flow height owner:

1. `mskba-home-story__art`: absolutely positioned backdrop
   with the four independently accessible transparent PNG canvases:
   `layer-1-backstage.png`, `layer-2-fense.png`,
   `layer-3-hoop.png`, `layer-4-player.png`.
   Existing filename `fense` is intentionally preserved.
2. `mskba-home-story__hero-slot`: the **unchanged** previously published
   Page Builder HTML, its Hero Slider, and its **existing Ball Director**.
3. `mskba-venues-scene`: the new trusted Blade introduction,
   laid out in normal document flow. The background is shared, not duplicated.

No new sticky/fixed hero, native scroll interception, extra ball, or changes to
the photographed sprite. The current `#hero.bg-lightgrey` background becomes
transparent *only inside the staged story*; the slider itself stays opaque.

Desktop layout: two-column image/content composition, background display heading,
eyebrow, title, description, CTA, four clickable highlights. Tablet rearranges
the layout/highlights. Mobile stacks the readable content **below an artwork
window** and uses a 2×2 highlight grid rather than shrinking desktop columns.

`resources/css/page-builder-venues-scene.css` is imported through the trusted
Page Builder Vite entry. No editor JavaScript/CSS is injected and no arbitrary
HTML sanitizer settings are weakened.

## Deliberately postponed to subsequent PRs

- Scroll-controlled parallax transforms for the four layers.
- Scroll-controlled right-to-left entrance of the large gray duplicate title.
- Highlights appearance at the halfway scroll threshold.
- Single-ball Hero → Venues handoff, shrink, registration near the player's hand,
  and reverse-scroll behavior.
- Structured Filament controls and configuration snapshots, graphic optimization.

For PR 1 all content and highlights are visible **without JavaScript**, and the
four layers remain static. That is intentional to simplify layout acceptance
before spatial tracking and animation are added.

## QA and acceptance

1. Confirm the full PR CI (PHP tests and frontend build), merge to `dev`
   and confirm `deploy_dev` success.
2. On `https://dev.mskba.ru/` at widths 360/390/640/768/1024/1440,
   confirm no horizontal overflow and no transparent gaps.
3. Check that the sky/fence/hoop/player layers retain relative alignment;
   compare the visual with the provided desktop screenshot.
4. Verify current hero slides, CTA, marquee/highlights and rolling ball
   still function as before (including upward scrolling).
5. Check the new Venues link, the 2×2 mobile highlights, foreground contrast
   and that text does not overlap the player's face/hand.
6. Check `prefers-reduced-motion`, keyboard focus, and alt/aria labels.
7. Check published non-home pages are unchanged; an unpublished draft preview
   does not acquire the staged scene.
8. The previously approved staging scrollTop diagnostic is **not removed**
   in PR 1; remove it after the ongoing gesture investigation is closed.

Known limitations: final visual alignment has not yet been approved on a
physical iPhone; all four existing PNGs total about 6.6 MB, so optimized
variants should be prepared before moving this composition to production.
