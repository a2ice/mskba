# MSKBA Uniform Studio MVP

## API
Public: GET /uniforms/workshop; GET /uniforms/options.
Auth-only: GET /account/uniforms; GET+POST /uniforms/projects;
GET+PUT+DELETE /uniforms/projects/{design};
GET /uniforms/projects/{design}/logo.

Fields: name (2–100), primary_color (#RRGGBB), optional secondary_color (#RRGGBB or null),
sizes[] (XS S M L XL XXL), sleeve_style (sleeveless/sleeved),
logo_source (none/team/custom),
logo_team_id for team, logo upload PNG/JPEG/WebP max 2MB for custom.
Additional SVG fields: jersey_number (1–999; displayed with at least two digits),
number_color (optional #RRGGBB), number_outline (boolean),
number_outline_color (optional #RRGGBB), logo_front_position (none/left/right),
logo_back_position (none/above/below). Old API clients get safe defaults.

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

## SVG studio v2 (four orthographic views)

The single SVG component `UniformKitPreview.vue` is used both by the interactive
editor and the saved design collection. Four front/left/right/rear perspectives
follow the supplied basketball jersey + shorts reference: realistic armholes,
neck piping, tapered jersey, side panels, elastic waistband, independent legs,
seams and restrained fabric shading. No hard-coded MSKBA logo/number remains.
The stage defaults to neutral blue-gray fabric without secondary trim.

The editor offers a view selector and scales the SVG draft proportionally using
XS/S/M/L/XL/XXL sizes selected for the design. This is a preview scale, not an
industrial garment grading chart. Sleeved style is disabled until there is a
real pattern. Color/number/outline and independent logo placements round-trip
through the API and restore after authentication. Number 1–9 displays as 01–09;
up to 999 is allowed. The number is auto-contrasted against the jersey if
custom pigments are not selected. Logo placement is explicit on front and back.

## Auth
Unauthenticated save stores the draft in sessionStorage and invokes
mskba:auth:open using the existing MSKBA auth modal. After sign-in
(mskba:auth:success) or registration/onboarding, pending drafts are
persisted to the authenticated API and redirected to /account/uniforms.
Never claim a successful save before HTTP success. Production option
is a placeholder; the 2D SVG is not yet a manufacturing pattern nor a 3D model.
The existing avatar flow can select the saved design; new visual settings are
stored on that same design so future renderer upgrades can use them.

## QA
Check CI, routes, create/edit/delete, file restrictions, 404 isolation,
keyboard, drag, 1440/1024/768/390/360px, Page Builder scroll and profile.
