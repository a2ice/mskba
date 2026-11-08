# Uniform workshop — compact preview, one size, side arrows (09.11.2026)

## Acceptance criteria
- All jersey and shorts are visible at every size including XXL, both in the public editor and the modal. The SVG viewBox provides enough margin for the maximum size without clipping.
- Replace the four top tabs with two accessible side-of-garment arrow buttons. The **right arrow** cycles exactly: front → left → rear → right → front; the **left arrow** is the reverse. All view changes wrap and retain edits to color and number positions.
- One size dropdown appears in the settings panel where the previous sizes checkboxes were. The selected value is both the design's **only** saved size and the SVG preview size. Remove the bottom preview-size dropdown.
- Keep the existing “Сбросить положение” action for number placement; do not add a duplicate recenter button.
- For backward compatibility with the JSON array field, the UI sends sizes[] with one element. Existing multi-size designs select their first valid size on editor load and are narrowed to a single size when the owner saves them; no data migration is needed.
- Confirm desktop/modal/mobile compact layout, keyboard-accessible arrows, Vite build, tests, CI and deployment only to dev. main and production remain untouched.

## Testing
- Node test of the complete four-step right and left traversal, reversal identity and legacy size normalization.
- PHP feature contract ensuring one dropdown, no top tabs or preview-size select, the exact navigation calls, retained number reset, and singleton array persistence.
- Visual check at XS / XXL in local browser; manual navigation and dropdown check after dev deployment.
