# Page Builder CSS & runtime asset lifecycle (Phase 1)

## Scope of this change

- Full CSS property and selector syntax is accepted in the Filament Page Builder
  editors (page, template, ordinary component) without a per-property allowlist.
- CSS is wrapped in native `@scope (.mskba-page-builder)` so selectors apply only
  to the page builder canvas. Modern CSS rules such as `@media`, `@container`,
  `@supports`, nested rules, `@keyframes`, custom properties and pseudos are
  accepted as written. Bad declarations are ignored by the browser.
- The server rejects `</style>` breakouts, PHP/HTML injections, forbidden
  `@import`, `@charset`, `@namespace` and unbalanced braces/comments/quotes.
  No arbitrary JavaScript is accepted.
- Scoping requires native browser CSS `@scope` support (current Chromium,
  Firefox and Safari); scope rules do **not** sandbox network fetches or
  all global descriptor at-rules. Editors must be trusted with publication rights.
- Page CSS and Vite CSS now load through the existing theme `styles` head stack;
  Vite JS loads through the `scripts` stack before `</body>`. `<main>`
  contains only builder content.

## Revisions are distinct from browser asset versions

The database already stores `draft_css`, `published_css`, plus publication
snapshots in `page_builder_revisions`. This change **does not add dynamic
file generation**, storage directories or compile/minification steps.

For a later phase, a stable or content-hashed route may serve published CSS
and separately permissioned superadmin JS directly from published database
snapshots using appropriate ETag / caching. JS must never execute in an
unisolated draft preview and should have a separate approval and kill switch.
This phase intentionally **does not** enable executable JS in Filament.

## Manual QA

1. Save and publish `.slider-cta { min-width:175px; white-space:nowrap; }`.
2. Verify CSS appears under `<head>`; `page-builder.js` is inserted before
   `</body>`, not inside `<main>`.
3. Verify responsive CSS, pseudo-elements and `@keyframes` on mobile/tablet.
4. Verify script markup and `</style><script>...` remain blocked.
5. Verify revert via existing revision workflow and drafts do not change public CSS.
