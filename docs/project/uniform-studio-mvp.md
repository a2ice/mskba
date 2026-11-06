# MSKBA Uniform Studio MVP

## API
Public: GET /uniforms/workshop; GET /uniforms/options.
Auth-only: GET /account/uniforms; GET+POST /uniforms/projects;
GET+PUT+DELETE /uniforms/projects/{design};
GET /uniforms/projects/{design}/logo.

Fields: name (2–100), primary_color and secondary_color (#RRGGBB),
sizes[] (XS S M L XL XXL), sleeve_style (sleeveless/sleeved),
logo_source (none/team/custom),
logo_team_id for team, logo upload PNG/JPEG/WebP max 2MB for custom.

Private media is attached via morph relation to UniformDesign.
Team logo access is limited to created or accepted teams with available logo.
All read/write operations use canonical identity checks. Deleted projects
soft-delete and remove private media.

## Single editor
resources/themes/mskba_app/js/uniforms/UniformWorkshop.vue is shared by
the standalone public page, modal on homepage, and player avatar generator.
bootstrap.js uses dynamic import to avoid adding heavy UI during homepage scroll.
Main homepage remains Filament Page Builder owned. The published Filament component #7 wraps its workshop CTA in
.tab-content[data-tab-name=workshop] .tab-footer-actions > button.section-cta;
this is supported as a compatibility bridge (as is the previous markup).
Preferred explicit button attribute is data-mskba-open-workshop=1 (optional
data-mskba-workshop-context=home).

## Auth
Unauthenticated save stores the draft in sessionStorage and invokes
mskba:auth:open using the existing MSKBA auth modal. After sign-in
(mskba:auth:success) or registration/onboarding, pending drafts are
persisted to the authenticated API and redirected to /account/uniforms.
Never claim a successful save before HTTP success. Production option
is a placeholder; saved design is not yet an avatar-generation payload
or a manufacture order.

## QA
Check CI, routes, create/edit/delete, file restrictions, 404 isolation,
keyboard, drag, 1440/1024/768/390/360px, Page Builder scroll and profile.
