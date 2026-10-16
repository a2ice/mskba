# MSKBA Page Builder (first implementation)

## Scope (2026-10-16)

The builder uses the existing Filament v5 backoffice, design palettes, and
Laravel routes. It does NOT replace the legacy welcome page automatically.

- **Content → Pages**: URL, page-specific HTML/CSS, SEO, preview and publish.
- **Design → Templates**: reusable HTML/CSS with \`[[page_content]]\` placeholder.
- **Design → Components**: reusable HTML/CSS, inserted with
  \`<mskba-component name="hero-banner"></mskba-component>\`.
- **Dynamic sources**: built-in \`games-list\` renderer, e.g.
  \`<mskba-component name="games-list" source="my_games" limit="5"></mskba-component>\`.
  The backend renders a shimmer skeleton, the browser fetches the typed data
  (limit 1-12), replaces content with safe DOM text and relative links.
  Available data: \`upcoming_games\`, \`my_games\`, \`featured_teams\`.
- **Permissions**: \`backoffice.page_builder.view/edit/publish\` require
  \`backoffice.access\`. Only superadmins have all three by default.
- **Publish**: edit/save draft, then explicitly publish with confirmation.
  The editor's `path` is a draft URL; `published_path` remains fixed until publication.
  Existing public URLs are backfilled and cannot be claimed by another page.
  Published MSKBA app pages emit a canonical link. 
  Published HTML, CSS, SEO and template selection are separate snapshot fields.
  Publication records a revision. Restore changes only the *draft*; to roll back
  the public view, restore draft, save, and publish again.
  Publishing a page with a missing, unpublished or malformed component
  placeholder is rejected before the public snapshot changes; the editor is
  told to publish its components first. Dynamic `games-list` requires an
  allowed source. The preview may still show component drafts.
- **Preview**: authenticated backoffice route; noindex/no-store.
- **Routes**: \`/\` uses the builder only after the homepage is published;
  legacy homepage remains unchanged otherwise. New one-segment URLs
  e.g. \`/about\` are served only when published. System routes are reserved
  and existing explicit Laravel routes take precedence.
- **SEO**: published title, description and route-aware canonical URL.
- **Design themes**: CSS can reference the existing CSS tokens; builder rules
  are prefixed with \`.mskba-page-builder\` to prevent styling surrounding UI.

## Editor layout in Filament 5

Page, template and generic component edit forms use a single outer column.
Metadata is followed by a full-width code editor, with the placeholder guide
in a collapsible section **below** HTML/CSS instead of consuming a third of the
code editing area. The Hero Slider's structured component form retains its
internal multi-column fields but occupies the full available outer width.
The layout applies equally to create and edit screens; it does not change
stored content, publishing, preview URLs or validation.

## Security rules

No arbitrary PHP, Blade, SQL, JavaScript, iframe or inline event attributes
are accepted from editor code. Symfony HtmlSanitizer restricts the HTML
allowlist. CSS accepts only inert, whitelisted declarations and strictly scoped
selectors; no imports, URL requests or global rules. Dynamic sources are PHP
allowlist entries, never user-provided SQL/model names. Private \`my_games\`
requires an authenticated caller and is not cached. All JSON reads return
\`Cache-Control: private, no-store\`. Query limits cap at 12; use only the
public subset of event and team fields in anonymous responses.

## Important limitations / next iterations

- The first release is **code-first**, not WYSIWYG. Add the visual editor
  against the same structured component model later.
- Complex animated hero slider, media management, component-specific setting
  panels, and embedded Vue/JS modules are separate approved implementation
  increments. Do not allow raw JS code in page records as a shortcut.
- Component nesting and arbitrary CSS at-rules are intentionally unavailable.
- Additional system pages and multi-segment CMS routes will need individual
  route ownership reviews. An explicit existing route always wins.
- Existing dashboard widgets remain in their separate subsystem until a
  dedicated migration/adapter is designed; do not silently replace them.
- Keep homepage unpublished until public design and hero-slide layers
  receive explicit approval.
