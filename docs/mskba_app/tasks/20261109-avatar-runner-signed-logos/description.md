# DEV GitHub avatar runner: signed uniform logo references (09.11.2026)

## Incident
Several GitHub Actions avatar runs on 08–09 November returned HTTP 404 while fetching a signed reference, before requesting OpenAI. The generic runner trap incorrectly exposed "OpenAI generation failed" to the user.

## Root cause
After avatar logo ownership moved to UniformDesign, the signed-reference controller still looked exclusively for legacy User `player_generation_logos`, or assumed a team-sourced logo always belonged to the selected team. This breaks personal/team uniforms with uploaded crests and personally selected team logos.

## Changes
- Save selected `uniform.design_id` and `uniform.logo_team_id` in the generation snapshot.
- Resolve custom media through the exact saved UniformDesign, checking ownership for personal designs and team identity for team designs.
- Resolve team-sourced crest through the design's `logoTeam.logo`, retain default team crest fallback and legacy in-flight references.
- For DEV only set `GITHUB_AI_REF=dev` in deployment. Production main is untouched.
- DEV workflow reports the logical reference slot + HTTP status (never signed URLs or image bytes). Invalid reference fails with `reference_unavailable` and a user-facing explanation rather than a false OpenAI error.
- Keep signed endpoint access controls, callback signatures, billing refunds and original generation flow.
- Add private signed reference tests for personal/club uniforms and foreign-owner protection.

No automatic paid generation is triggered by testing.
