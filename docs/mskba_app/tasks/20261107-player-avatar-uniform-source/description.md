# Player avatar uniform source selector (2026-11-07)

## Goal
The player's avatar generation page offers a single **Форма** source selector:
1. Нейтральная форма (always available);
2. Форма команды (only for an accepted, active player-team membership);
3. Моя форма (only when the current canonical account owns saved UniformDesign projects);
4. Создать свою форму (opens the shared Git-owned UniformWorkshop modal).

## UI specification / guideline
- Retain native select-control and check-label styles of MSKBA App and existing section layout: **Форма** and **Кроссовки** share the first row.
- **Форма команды** exposes a required **Команда** selector. A team without configured home kit colors uses the project's neutral basketball kit; do not synthesize branding or team colors.
- **Моя форма** exposes a required selector named **Мои формы**, populated only with designs owned by the user, labeled by project name.
- **Нейтральная форма** hides and disables both dependent selects.
- **Создать свою форму** opens the shared native `mskba-modal` workshop and immediately reverts the source selector to **Нейтральная форма** until a save response confirms persistence.
- If the modal closes without a new saved design (close button, Escape or backdrop), keep **Нейтральная форма**, with no dependent selectors. If save succeeds, stay on the player profile (do NOT redirect to `/account/uniforms`), re-fetch `/uniforms/projects`, enable **Моя форма**, and select the newly saved project's ID; if the re-fetch fails, the successful POST response can populate that single entry. Ordinary workshop/page saves keep their existing navigation behavior.
- Saved uniform projects already contain a logo choice. When **Моя форма** is selected, the separate avatar logo override is hidden and the saved design's owned custom/team crest is passed as a reference automatically. For team/neutral forms the existing separate logo override remains available.

## Backend contract
- Extend `generate_2d` request with `generation_uniform_source` (`neutral|team|mine`) and `generation_uniform_design_id` (for `mine`); re-use `generation_team_id` only for `team`.
- Resolve `mine` via `UniformDesign::whereIn('user_id', $user->identityIds())` before charging. Foreign, removed or unknown IDs return 422; team membership and active contracts remain mandatory for team selection.
- Preserve source and selected design/team IDs in the player's character preferences, so reloading the page restores the choice.
- `uniform.source=neutral`: neutral slate/gray kit. `uniform.source=team`: configured `Team::colors.home_primary/home_secondary` when a primary color exists; otherwise force neutral. `uniform.source=mine`: exact saved primary/secondary colors and `sleeve_style` (`sleeved|sleeveless`). Do not claim the design is a production sewing pattern.
- The selected uniform's logo is passed as the last image reference to the AI gateway when available; no new image storage or public permission is introduced.
- Existing legacy generation clients that pass `generation_team_id` but not the new source retain the team source behavior.

## Acceptance
- No team / no saved uniforms: only neutral and create options.
- Team member: required team dropdown; team with no colors renders neutral.
- Saved designs: named dropdown, selected design restored after reload.
- Create in avatar popup: save without redirect and select new design; cancel/close without save stays neutral.
- User cannot select someone else's saved uniform ID; private saved design logo remains authorized.
- PHPUnit contracts, prompt snapshot assertions, full CI and Vite build. Deploy to dev only and verify `/up` and frontend.
