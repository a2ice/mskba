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

Do not insert the ball into a slider's individual slide; it must be a sibling
of the Hero Slider in the same `mskba-hero-scene` wrapper. Do not add
`<script>`, inline CSS, SVG, or event-handler attributes to editor HTML.
**Slider itself is intentionally untouched.**

The sprite must be exactly 1024 x 1024, a 4 x 4 grid of 256 x 256
square frames. The approved file is now tracked at
`public/images/ball-16.webp` and is served at `/images/ball-16.webp`.
A different size will not activate the overlay, by design.

## Current behavior

- Three geometric paths selected by **actual scene width** (<=640,
  641–1050, >1050), not browser device type.
- Responsive ball tile: 122/165/195 CSS px; scale grows 0.75 -> 1.00.
- Six full cycles of the original 16 frames; last step is frame 1.
- No frame blending or optical flow; dark (not transparent) ball initially,
  gradually brighter as the ball rolls closer.
- Motion is tied to native page scroll. Hero never gets `position: fixed`
  or `sticky`; ball stays in its final relative position after movement.
- A single decorative overlay, consistently visible over all slides.
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

**Do not touch `main`/production.** Publish the page markup separately only
after visual acceptance and asset upload.
