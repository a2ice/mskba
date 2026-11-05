# MSKBA-604 — Форма и Мастерская (главная V3)

## UI requirement and rationale
The existing V3 arena already has five full-page story sections. The fourth section needs two connected actions without a wholesale redesign: selecting ready-made kit (players / partner suppliers) or designing a kit (team identity, draft and eventual manufacturer). Heading, ghost lettering, arena players, referee and rolling ball remain owned by Filament Page Builder; this delivery must not modify their positioning or scrolling.

## Design system trace
- Preserve dark arena palette (#071728–#102b46), high contrast white typography and orange action accent (#ff821c).
- Reuse typography, rounded CTAs and transparent layered panels; avoid introducing bright marketplace logo walls or dense retail catalog cards.
- Only a contained 510px copy-column enhancement goes into #venues-story-uniforms; do not create new fixed/sticky layers.
- Two mutually exclusive content routes use a compact accessible two-button segmented switch. A discreet closing line bridges to Rankings.
- Native accessible modal uses Escape, close button and background click; content is centrally managed by FAQ and links to full articles. Workshop modal is wider than info dialog.
- Shared kit SVG/controls render in both modal and dedicated /uniforms/workshop. Contrast and keyboard focus required.
- Mobile under 720px uses stacked editor and near-fullscreen dialog; under 560px compact text and preview. No horizontal page overflow. Respect prefers-reduced-motion.
- Progressive enhancement: original Page Builder copy remains when JS cannot load; hide it only after Vue successfully mounts.
- No false partner badges or working marketplace/checkout claims in MVP. Logos of named marketplaces intentionally omitted until an actual partnership exists.
- Avoid expensive GPU filters on existing high-resolution actor overlays while scroll-performance work is underway.

## Verification and ownership
- Frontend: Vue components in resources/themes/mskba_app/js/uniforms; scoped design CSS.
- HTML: the only change is to frontend-injected content after the section is found. Filament-published HTML/CSS/JS continue to be editable.
- Content: four FAQ articles seeded idempotently; edited articles are not overwritten.
- Acceptance: 1440, 1024, 768, 390, 360 widths; forward/backward scroll; modal keyboard and backdrop; FAQ and CTA; offline JS fallback; same controls on standalone route.
- Implementation status: see docs/project/uniform-workshop-v1.md; this is the design trace required for UI CI.
