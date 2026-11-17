# Player character generation: result scroll, body proportions, team logo (2026-11-07)

## Scope
The MSKBA App player profile is authored by `resources/themes/mskba_app` rather than the legacy `mskba_dark` form. This change is restricted to the player avatar generation UI and the shared backend generation prompt and preferences endpoint.

## UX / design-system alignment
- A newly completed generation scrolls the page to the start of the physical player section, so the freshly rendered avatar is visible. Scroll is called only for the current action's `completed` or synchronous success result, never for normal page load, history restoration, errors or manual avatar selection.
- The target is the **non-sticky section**; a sticky preview's `scrollIntoView` would not bring the document back to the start. Respect existing sticky header and tab offsets. Respect `prefers-reduced-motion`.
- Selecting "С логотипом" defaults to the selected team's existing `Team::logo` Media asset, not a generic placeholder. Users may instead select one of their separately uploaded private logos. Choosing a team logo does not spend one of the three custom-logo slots.
- The mini-logo is a native button matching the existing 35 px tile. Its expanded view uses the site's `mskba-modal`/header/close control; the ONLY visible content is the heading "Логотип команды" and the original logo image (object-fit contain). No explanatory text or extra CTA.
- Team logo URLs are server supplied and same-origin validated. Team access is restricted through the existing accepted player membership and active contract query. The preference endpoint and HTML option derive the image from the identical team logo Media relation.
- The selected mode `none|team|custom` is sent to the existing backend generation contract; a selected personal logo still sends its authorized ID and a team logo uses the team's Media asset.

## Model prompt fidelity
- For female profiles only, convert the validated `chest_volume=small|medium|large|full` option to an explicit photorealistic body-shape instruction and preserve natural athletic anatomy and jersey drape.
- A "full" setting must produce visibly different upper-torso volume than "medium"; no irrelevant changes to face, weight, height, body type or costume are requested.
- The provider remains probabilistic; acceptance needs a visual A/B check using the same face references and other settings.

## Verification
- PHPUnit: female chest prompt differentiation; legacy male prompt unaffected; saved logo preferences returned.
- CI: PHP tests + Vite build.
- Staging smoke: selected team crest appears in preview and selector, opening mini-logo shows standard modal, completion scrolls to newly generated avatar.
- No changes to production deploy or secrets.
