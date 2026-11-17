# Player uniform inline edit, explicit headband and ruler layering — 2026-11-07

## Scope
UI on `/account/participation/player?tab=physical`, MSKBA App only. No changes to billing, logos ownership, storage or production.

## Inline editing of the saved uniform
- When source is **Моя форма**, the existing named design selector gets a compact **Посмотреть** button immediately to its right. If no design is selected or the source is not **Моя форма**, the button cannot be used.
- The button opens the **same existing native uniform workshop modal** with `projectId` of the selected owned design; the backend GET/PUT owner authorization is unchanged.
- The workshop hydrates the saved settings, displays its preview and offers **Сохранить изменения**. Saving in the player context stays on the player page and updates the named design dropdown without changing selection. Closing without saving preserves the previously selected design; creating a new design still follows the existing neutral-on-cancel behavior.
- Use standard account buttons, select-control, modal header/close UI, keyboard access and `aria-controls` pointed at the modal ID. Keep the button beside the select on desktop; avoid overflowing smaller layouts.

## Accessory fidelity
- The allowed `headband` attribute was already submitted via `character[attributes][]` and normalized by the server. The AI prompt previously used only the opaque token `headband`, which was insufficient to guarantee visually distinct placement.
- The prompt now translates all supported accessory tokens into concrete anatomical instructions, especially the selected forehead headband (visible horizontal cloth band above eyebrows, in front of hair; never an elbow pad or wristband).
- Do not mention the mandatory headband instruction for unselected cases; no changes to saved accessory contract or personal data.

## Ruler
- Scale ticks and calibration lines remain visible behind the transparent player silhouette. The avatar figure gets a higher stacking order than the scale; scale labels stay in the left gutter and the figure does not intercept ruler pointer interactions.
- Preserve the existing height calibration, floor baseline and sticky preview behavior.

## Acceptance
- Select saved form A → click **Посмотреть** → modal loads A → change name/colors → save → popup closes, A remains selected with new name.
- Close edit modal without saving → selection remains A. Creating from the source select and cancelling still goes to neutral.
- Generate with headband selected → payload includes `headband`, AI prompt explicitly locates it on the forehead, not on the arm. Visual compliance still requires a real API generation.
- Ruler lines no longer overlap the rendered player; calibration labels and marker remain.
- PHP contract and prompt tests, Vite build, dev-only deploy and `/up` health check.
