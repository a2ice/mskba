# Task — Independent uniform artwork and GitHub AI manifest reliability (2026-11-09)

## Background
The user reports that the front number's position and scale also move the rear number, that the logo is not directly editable, and that the player avatar fails with "OpenAI generation failed on GitHub runner".

## Editor changes
- Reuse the existing SVG gesture model for each independently selectable mark: front jersey number, rear jersey number, front shorts number, chest logo, back logo, shorts logo.
- Every mark has its own persisted x, y, scale; changing the front mark must never mutate the back mark. Separate the shared old jersey coordinates into front and back records.
- Selection begins only when clicking a mark, shows a dashed selection outline and circular resize handle; clicking elsewhere in the editor or changing view clears selection. Do not draw persistent handles over the artwork.
- Remove the scale slider, the numbers/shorts tabs, and the crossed-out usage hints. Keep contextual "Сбросить положение" for selected mark.
- Keep selecting the logo's SOURCE ("Моя команда" or upload) but replace front/back placement dropdowns with independent checkboxes "Логотип спереди", "Логотип сзади", "Логотип на шортах". Each checkbox renders exactly the corresponding SVG mark.
- Number visibility remains independent of all three logo checkboxes. Turning "С номером" off does not hide logos or delete stored coordinates.
- New schema fields are nullable only where appropriate, validated with ranges, and have useful defaults. Existing saved designs and legacy drafts keep their placement during migration and read/update.

## AI failure investigation
- GitHub Actions workflow "OpenAI player generation" had multiple failures on 2026-11-08/09, e.g. run 42538057218 and 42536921735. The raw log reports curl HTTP 404 while downloading the signed generation manifest, before calling the images edits endpoint. A known earlier run 42519066086 succeeded.
- API integration route is present and accessible: an unsigned request for an existing generation gets 403, one for an unknown generation gets 404. The temporary 404 indicates the generation record was not visible through that endpoint when the runner started (exact DB/storage reason not established).
- Add bounded retry with explicit status logging for 404/transient errors; preserve failure on 403, other nonretryable statuses. Avoid printing signed URLs or secrets. Do not claim that model availability or price is the cause.
- Front-avatar prompt should include only enabled front/shorts logos, never artwork configured exclusively on the back, and respect independent front/shorts artwork positions and sizes.

## Acceptance
- Four view cycles remain correct.
- Move and scale each of the six marks independently, deselect by clicking blank space or editor settings, then reselect and reset.
- Logo front/back/shorts checkboxes are independent, saved and restored in library and authenticated/guest flows.
- Feature tests verify CRUD, coordinates/flags validation, legacy defaults and unrelated mark preservation.
- AI prompt tests verify back-only vs shorts-only logo placement.
- Vite build, PHP syntax and CI green; merge/deploy **dev only** after green, production not touched. A fresh real AI generation requires user-authorized testing because it incurs a charge.
