# Uniform workshop — compact preview and side arrows (09.11.2026)

## Acceptance criteria
- All jersey and shorts are visible at every size including XXL, both in the public editor and the modal. The SVG viewBox provides enough margin for the maximum size without clipping.
- Replace the four top tabs with two accessible side-of-garment arrow buttons. The **right arrow** cycles exactly: front → left → rear → right → front; the **left arrow** is the reverse. All view changes wrap and retain edits to color and number positions.
- Superseded on 09.11.2026: the size dropdown was removed from both editing and read-only workshop interfaces. The preview uses a fixed reference scale; historical sizes[] stays backend-only.
- Keep the existing “Сбросить положение” action for number placement; do not add a duplicate recenter button.
- Superseded on 09.11.2026: the client now preserves previously saved sizes[] arrays without narrowing them on edit. New designs submit the hidden legacy default ['M']; no migration is needed.
- Confirm desktop/modal/mobile compact layout, keyboard-accessible arrows, Vite build, tests, CI and deployment only to dev. main and production remain untouched.

## Testing
- Node test of the complete four-step right and left traversal and reversal identity.
- PHP feature contract ensuring no size controls in either workshop mode, cyclic navigation, and preservation of historical backend metadata.
- Visual check at XS / XXL in local browser; manual navigation and dropdown check after dev deployment.
