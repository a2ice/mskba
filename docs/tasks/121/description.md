# Task 121 — 3D metric viewport and fallback cleanup

- Fix orthographic camera so world Y=0..2.5 m maps exactly to 0..250 cm.
- Fix world X=-1..1 m so stage width is exactly 200 cm.
- Preserve the 4:5 metric plot inside the 10 px visual safe-area.
- Remove eager SVG character rendering; use a lightweight error fallback only if 3D fails.
- Fix lifecycle status text lookup.
