# Player profile avatar: correct first paint (09.11.2026)

## Problem
The physical player silhouette was first painted at CSS default 74% scale before JS re-calculated the stored height/weight/body type. Completed avatars then replaced that silhouette after a separate generation-history AJAX call. Face photos also first appeared as placeholders and were subsequently fetched through generation_preferences.

## Solution
- In the active MSKBA App player Blade view, render initial height and width CSS variables based on saved physical fields, with identical BMI/body-adjustment math to updateFigure(). The height marker already uses server values. The default silhouette is displayed correctly from the first HTML paint, with no loader.
- The server selects the primary **completed and image-bearing** generation (or latest eligible completed generation) for the authenticated user. Its private, own-generation image route is the initial image src. The body silhouette is never displayed as an interim frame when a stored generation exists.
- Only generated avatar images need a local loading layer/spinner. Hide it after the actual image load, including cached images; on failure, fall back to the correctly sized silhouette. For gallery selection, preload into Image() before swapping the displayed src; cancel obsolete image requests.
- Initial face photo URLs and green verified flags are rendered by Blade using the same private per-slot endpoint and verification semantics as generation_preferences. No initial AJAX face reload; refresh still happens after generation and face upload/delete mutations update their own tiles.
- Both HTML page and generation_preferences use PlayerCharacterInitialRenderService for a consistent user-specific face reference snapshot. All user-owned media/image routes retain their authorization and no-store cache behavior.

## Acceptance
- Slow JS boot no longer causes a silhouette size jump or placeholder-to-generated-avatar flash.
- No generated avatar: correct physical measurements are visible immediately, no loading overlay.
- Generated avatar: it is in the initial HTML, loading layer clears on successful load or failure fallback.
- Uploaded face photos and verification badges appear on first server HTML, not after AJAX.
- Existing avatar gallery selection, paid generation, gender/physical controls and authorization remain unchanged.
- Feature tests and normal CI/Vite build pass; deploy to dev only.
