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

The photographed source sprite `public/images/ball-16.png` is 1254 x 1254,
with 16 RGBA frames in a 4 x 4 grid. Each tile is cropped independently
using rounded quarter-sheet boundaries and resized to 256 x 256, avoiding
bleed across half-pixel tile boundaries. The optimized file is `public/images/ball-16.webp` (1024 x 1024, 4 x 4).
Each original photograph is uniformly resized to 226 px and centered inside
a 256 px transparent atlas cell using its alpha silhouette (threshold 32).
Frame alpha centroids have ≤1.5 px deviation from the common center. No
photographic content, seam, logo or texture is redrawn or rotated.
A CSS factor of 256/226 retains the previous apparent ball size.
The transparent centered WebP is **240,472 B** vs **1,942,087 B**
source PNG (~87.6% smaller).
The homepage's existing Filament HTML continues to reference
`/images/ball-16.webp`. The frontend uses `?v=photo-20261017-centered-v2` to
avoid the previous sprite from browser cache. The rendered image must be
1024 x 1024; other sizes are rejected before use.

## Current behavior

- **Straight diagonal roll** on the wood floor, not a Bezier arc. Both
  screen coordinates use exactly the same eased progress. Starting (x,y)
  and finishing (x,y) percentages of the rendered Hero:
  - mobile: (85%, 40.5%) → (20.5%, 100%), scale 0.525 → 1 (initial scale reduced by 30%, final unchanged);
  - tablet: (78.5%, 42.5%) → (29%, 100%), scale 0.43 → 1;
  - desktop: (74.5%, 44%) → (31.5%, 100%), scale 0.22 → 1.
  The end point remains half the ball outside the bottom of the Hero.
- Sprite frames and ball coordinates are **pure functions of the same
  clamped native page scroll position**. No 55ms exponential follower and no
  smoothstep easing of the trajectory. The point on the straight line is
  interpolated linearly from `p=0` to `p=1`, as is the apparent scale.
- 16 photographed frames make **one complete revolution** over the first
  scene's scroll range, with equal distance between frame changes.
  For the usual 189 px timeline this is ~11.8 page-scroll pixels per frame.
  This is device-independent whether the user scrolls with wheel, keyboard,
  touchpad or touch. Larger wheel steps necessarily skip intermediate frames:
  16 still photos cannot synthesize frames that do not exist.
- After `p=1`, exactly the same x, y, size, and frame zero are repainted, while
  the entire Hero continues to scroll normally. No freezing the element in
  viewport or capturing screen pixels; reverse scroll reconstructs the same
  frames and coordinates. Reduced motion remains at the settled state.
- Only brightness progression is eased; that does not affect the position or
  frame progression. The photographed order 0..15 determines rotation
  direction; no extra CSS rotation.
- Scroll-linear animation is deliberately a **visual** model, not a no-slip
  3D simulation; the earlier changing-angular-velocity algorithm caused
  inconsistent frame cadence during scale-up.

### iPhone Safari fast flicks

- Mobile Safari's collapsing browser toolbar can issue viewport `resize`
  while the page is still moving. Height-only scene/viewport changes must NOT
  recompute the scene's scroll start/end: both stay stable until the actual
  rendered slider WIDTH changes (rotation/breakpoint).
- The scroll handler now schedules at most one `requestAnimationFrame`
  callback per display frame and reads the latest native `scrollY` at paint
  time. This is *not* a second animation: there is no stored follower,
  easing, interpolation, or movement after the user's page scroll has
  settled. The ball's pose remains determined by the native scroll position.
- In fast swipes, a 189px timeline with only 16 photographed stills can
  necessarily skip some intermediate sprite frames when the browser scrolls
  over multiple ~12px steps between draws. That limitation requires either
  more real intermediate photos, longer scroll scene, or intentionally adding
  lag; it cannot be solved fully by changing event listeners alone.
- Native scroll remains uninterrupted: no touch/scroll hijacking, sticky
  hero, forced scroll snapping, or JavaScript `preventDefault` on touch.

- Landing remains 30% earlier than the old scroll-distance duration, with
  no fixed/sticky Hero or page-wide overlay.
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
- The **last frame is explicitly frame zero**, matching the opening frontal / anfas basketball sprite frame.
- Motion is tied to native page scroll. Hero never gets `position: fixed`
  or `sticky`; ball stays in its final relative position after movement.
- A single decorative overlay, consistently visible over all slides.
- Opt-in slider root allows overflow so half the ball can hang below the hero.
  Each individual slide still clips its background to the rounded corners.
- Layer order: background/scrim 0–1, ball 3, content/CTA min 4, navigation 8–9.
  Content editor z-index remains adjustable for other decorative graphics.
- Opt-in only if a real sprite image can load and its size is valid.
- Reduced-motion users get the final resting state.
- No extra CSS rotation: photographed frames already contain the intended roll axis.
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
4. If later needed, add a separate physically modeled flying-ball mode without altering scroll-linear frame cadence of the Hero.
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
