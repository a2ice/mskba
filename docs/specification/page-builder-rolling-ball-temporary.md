# Temporary independent scroll-ball overlay (MSKBA Page Builder)

> TODO: decide superadmin-only UI for trusted JS/animation parameters and safe asset management.
> Do not enable arbitrary JavaScript in the current HTML/CSS editor.

## Purpose

A basketball sprite animates over **all** Hero Slider slides, independently of
slide switch events. Its position is relative to the containing hero scene:
the scene scrolls normally, is never fixed/sticky, and the final center of the
ball is on the bottom edge (half outside the scene).

This behavior is opt-in: **nothing changes on existing pages** until the owner
adds the page markup and provides a published 4x4 sprite image.

## Page Builder markup

In the **HTML** tab on the homepage, use the following structure. The sprite
URL below points to the versioned public 1024x1024 WebP asset at
`public/images/ball-16.webp`. No Laravel storage symlink is required.
The template's img tag is only a sanitized asset carrier: first-party JS reads
its URL and paints one frame at a time.

```html
<section class="bg-lightgrey py-3" id="hero">
  <div class="container">
    <div class="mskba-hero-scene">
      <mskba-component name="hero-slider"></mskba-component>
      <div class="mskba-rolling-ball">
        <img class="mskba-rolling-ball__sprite"
             src="/images/ball-16.webp"
             alt="">
      </div>
    </div>
  </div>
</section>
```

Keep the HTML ball as a sibling of Hero Slider in `mskba-hero-scene`.
At runtime first-party JS moves it inside the **slider root**, never inside an
individual slide, so the shared ball sits behind every slide's text/CTA but
above the slide background. No Page Builder HTML changes are needed for this
upgrade. Do not add
`<script>`, inline CSS, SVG, or event-handler attributes to editor HTML.
**Slider itself is intentionally untouched.**

The sprite must be exactly 1024 x 1024, a 4 x 4 grid of 256 x 256
square frames. The approved file is now tracked at
`public/images/ball-16.webp` and is served at `/images/ball-16.webp`.
A different size will not activate the overlay, by design.

## Current behavior

- Three motion profiles selected by **actual scene width** (<=640,
  641–1050, >1050), not browser/device brand. Each profile controls its own
  path and scale; light level is currently shared across profiles:
  - mobile: ball tile 122 CSS px, scale **0.75 → 1.00**; existing path untouched
    (rightmost center at ~85.5% of Hero, final center at 20.5%).
  - tablet: tile 165 CSS px, scale **0.43 → 1.00**; rightmost center ~78.6%,
    final center at 29%.
  - desktop: tile 195 CSS px, scale **0.22 → 1.00**; rightmost center ~74.6%,
    final center at 31.5%, to the right of the CTA.
- Profiles describe **apparent size relative to court perspective**;
  end scale remains 1.00 on all screen sizes. First paint in CSS uses the
  same start coordinates, scale and lighting as the JS profile, so nothing
  snaps into position after the JavaScript bundle loads.
- Six full cycles of the original 16 frames; last step is frame 1.
- No frame blending or optical flow. The CSS pseudo-element renders frame zero
  **as soon as the stylesheet loads**, even before JS; the ball is never
  hidden with opacity at scroll progress zero. Starting brightness is **0.57**
  instead of **0.07**, approximately the previous half-timeline light level.
  It gradually brightens to 1.02 with saturation 0.74 → 1.00; no opacity
  fade and no black-circle silhouette.
- Motion starts with the **first scroll pixel** when Hero is already in
  the opening viewport (no arbitrary 107px pre-roll delay). For an opt-in
  scene below the fold, motion starts when it approaches the viewport.
- Scroll distance to the final landing is **70% of the previous distance**
  (30% earlier). A custom smooth easing has nonzero initial velocity but
  settles gently at the end. In the landed state progress is clamped to 1,
  and the ball remains positioned inside its natural Hero container.
- Six sprite cycles are retained; the **last frame is explicitly frame zero**,
  matching the opening frontal / anfas basketball sprite frame.
- Motion is tied to native page scroll. Hero never gets `position: fixed`
  or `sticky`; ball stays in its final relative position after movement.
- A single decorative overlay, consistently visible over all slides.
- Opt-in slider root allows overflow so half the ball can hang below the hero.
  Each individual slide still clips its background to the rounded corners.
- Layer order: background/scrim 0–1, ball 3, content/CTA min 4, navigation 8–9.
  Content editor z-index remains adjustable for other decorative graphics.
- Opt-in only if a real sprite image can load and its size is valid.
- Reduced-motion users get the final resting state.
- Sprite frame container has `rotate(90deg)`, matching the approved demo.
- Layer does not intercept pointer events or slider navigation.

## Integration files

- `resources/js/page-builder-rolling-ball.js` — opt-in interaction.
- `resources/css/page-builder-rolling-ball.css` — scoped styles.
- Imported by the existing `resources/js/page-builder.js` and
  `resources/css/page-builder.css` bundles.

## Limits / next iteration

1. Decide whether standalone page-effect assets should use Filament-managed
   uploads or versioned `public/images/` paths; verify public-storage symlinks
   and file permissions before migrating away from this temporary asset.
2. Allow superadmins to configure trusted animation options without storing
   arbitrary executable JavaScript (a separate design discussion).
3. Check that the ball is compatible with every slider background and with
   pagination/CTA in the final page design.
4. Consider controlling movement by real world surface travel instead of
   a fixed six rotations for a more physically accurate roll.
5. Maintain sanitizer boundary, existing published snapshots, and
   mobile reduced-motion support.

### Planned second scene, NOT implemented yet

Once the responsive Hero animation is accepted, the same physical ball should
continue into a second page section (e.g. a still photo of a basketball player
with a just-released shooting pose). The motion controller should support a
**scroll scene timeline**:

1. `hero-roll`: a scene-relative ball travels into the foreground and comes
   to rest half outside the Hero. Hero is not fixed; the ball follows its DOM.
2. `hero-exit handoff`: when natural page scroll carries the resting ball
   close to the fixed site header, capture its viewport position and hand the
   *same* ball (same frame, size and lighting) to a page-wide effects overlay.
3. `shot-intro`: scroll progress, section geometry and the player's release
   hand determine a new path, spin direction, z-order and scale. This should
   work in reverse on upward scroll, and preserve reduced-motion behavior.

**Do not prematurely attach a second copy of the ball to the next section.**
The second phase needs explicit ownership/layer handoff without a visible jump,
plus measurement of the real next-section artwork and release-point geometry.
The current hero-only controller remains the source of truth until acceptance.
Responsive profiles should eventually become editable Page Builder settings
for authorized editors, without duplicating movement code in user-supplied JS.

**Do not touch `main`/production.** Publish the page markup separately only
after visual acceptance and asset upload.
