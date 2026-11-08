# Task 655 — Reference accuracy and direct uniform number editing

## User request (9 November 2026)

PR #654's SVG did not match the supplied basketball uniform reference: shoulders
looked like short sleeves, the shirt and shorts had incorrect proportions, and
the number could not be resized or dragged. Rebuild the SVG, preserve four
orthographic views, move and resize jersey and shorts numbers independently.
Add checkbox "С номером" to hide number input/settings and all numbers on
garment when unchecked. Re-enabling restores the previous settings.

## Geometry

Use the photographic reference silhouette, not generic T-shirt paths.
Reference 960x960: front jersey x125–325/y182–528, shorts x119–330/y538–778;
side jersey x419–556/y183–535, shorts x418–556/y538–773; rear jersey
x635–834/y182–528, shorts x629–841/y538–778. Right side is a mirror
of the left reference. V-neck front, shallow rear neckline; side has
an open oval armhole. Long tapered jersey, narrow piping, straight
shorts with shallow crotch split. No sleeves or hard-coded name.

## Interaction and data

Neutral pale blue-gray default, optional secondary trim. Jersey front
and rear use linked jersey number coordinates, shorts number has its own
position and scale. Pointer drag to move, drag the circular handle or
use a slider to scale; arrow keys for precision. Clamp ranges and offer
reset for the selected part. Number disabled hides input/controls and
SVG numbers/handles, but preserves configured values for re-enable.

Persist number_enabled, jersey_number_x/y/scale and shorts_number_x/y/scale
via migration, model casts, validated API, guest drafts and previews in
My Uniforms. Old records default to enabled. Avatar prompt respects number
enabled/disabled and scale/position context.

## Acceptance

- Compare all 4 SVG views to supplied reference and verify deep armholes,
  V neck, straight shorts, trim and realistic proportions, no sleeves.
- Check light/dark and secondary colors, logo placement and 01–999 numbering.
- Desktop/modal/mobile: drag + slider + handle + keyboard; no accidental
  modal drag or page scroll when pointer is captured.
- Toggle checkbox off: no number fields and no number artwork; toggle back
  restores the exact saved number and independent placements.
- CRUD roundtrip, validation, CI, migration and Vite build.
- Release only to dev after successful checks; keep main/prod untouched.
