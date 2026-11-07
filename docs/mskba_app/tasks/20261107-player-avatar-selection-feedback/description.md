# Generated avatar auto-selection, error visibility and primary link

Date: 2026-11-07
Target: MSKBA App, /account/participation/player?tab=physical (dev only)

## Acceptance
- On a successful asynchronous avatar generation, the avatar identified by the completed generation ID becomes the selected/highlighted thumbnail and the large figure, even if a previous avatar was selected while the job ran. Scrolling to the preview keeps the existing sticky-navigation offset.
- A completed generation is auto-set as primary using the existing authorized API behavior. If setting primary fails but generation succeeds, the new avatar is still selected for preview and the user can choose it as primary manually. Never report a primary-selection failure as a generation failure.
- Treat the successful generation status endpoint's same-origin `image_url` as a temporary entry if the history endpoint has not yet included it; do not accept arbitrary external URLs.
- On a failed generation or rejected request, scroll to the actual error message below the generation form, taking sticky tabs and reduced-motion settings into account. Do not scroll to the avatar preview on failure.
- Replace the boxed **Сделать основным** button with an accessible link styled the same as **Основной аватар** (accent color, 12px bold, no underline including hover/focus). Retain the existing primary-selection mutation, busy guard, keyboard focus and feedback behavior; do not change the primary-selection permission rules.
- Keep background processing, credits and result-list pagination unchanged.

## Verification
- PHP contract tests confirm the link markup, click behavior, selected ID state, the success/failure scroll targets and same-origin preview fallback.
- Run the repository CI (PHP tests and frontend build), merge to `dev` only, verify the SSH deployment log and `/up`. A genuine paid OpenAI call is not required for this UI-only fix; an authenticated browser smoke of a completed generation remains appropriate.
