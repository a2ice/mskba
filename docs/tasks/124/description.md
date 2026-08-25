# Task 124 — image-based Player Character mock pipeline

Replace the currently active procedural 3D Player Character flow with an image-based preview flow prepared for a future OpenAI image API integration.

## Scope
- Keep the existing 3D implementation in the repository, but stop mounting it on the player profile page.
- Show one neutral CSS silhouette selected from the user's profile gender.
- Clicking/tapping the silhouette opens an appearance modal.
- Configure skin tone, hairstyle, hair color, facial hair, piercing, tattoos and tattoo locations; optionally upload a private face reference photo.
- Keep uniform color/team logo out of this iteration.
- Persist the appearance payload in `player_profiles.extra.character`.
- Store face reference uploads privately for future backend-only API use.
- Add a mock render state that mirrors the future asynchronous OpenAI integration.
- Mock generation supports both success and error paths and returns one of bundled fixed transparent PNG fixtures.
- Show a branded loading GIF inside the metric stage while mock generation is pending.
- Display a human-readable retry/edit state after mock errors.
- Render the returned PNG against the 0–250 cm metric stage; CSS controls display height from `height_cm`.

## Non-goals
- No real OpenAI request or API key.
- No public feature gating by verified contact yet.
- No uniform color/team logo generation.
- No deletion of the dormant 3D implementation.
