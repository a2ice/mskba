# Uniform workshop: size is not a design setting (09.11.2026)

## Decision
Size controls and size text must be absent from the shared workshop in personal/team editing and in read-only team preview. Sizing is an individual user concern, and the eventual UX for individual size selection is out of scope for this change.

## UI
- Remove the size dropdown/fieldset and read-only size row from the shared workshop. Use a fixed M reference size only internally for SVG geometry; do not label the preview with a garment size.
- Remove the redundant logo description rows and the explanatory footer note crossed out in the user's screenshot from read-only team view. The logo remains visible on the form illustration.
- Do not change the four-angle navigation, number controls, contractual edit authorization, or other design properties.

## API backward compatibility
- Keep UniformDesign.sizes storage, API validation, existing migrations and old values unchanged.
- The client still posts the backend-required sizes[] in create/update requests. When editing an existing form, preserve its complete original size array instead of truncating it. For new forms, use the old M default internally until this backend field is redesigned.
- Do not introduce a personal size preference, team membership size, copied team forms or an AI size property in this PR.

## Acceptance
- No displayed size setting in editable or read-only workshop modes.
- Existing saved sizes[] survive form editing, and the backend API continues accepting size metadata.
- Contract tests, Node navigation tests, Vite bundle and full CI must be green before merging into dev.
