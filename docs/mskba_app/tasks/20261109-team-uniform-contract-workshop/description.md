# Team-owned uniforms in the shared SVG workshop (2026-11-09)

## Goal
A permanent team owns one saved uniform design (not the creator's personal library). Its owner and holders of an accepted, active, assured team contract with `team.uniform.manage` can create/update it. A player belonging to the team sees the same SVG kit in the avatar form selector via **Посмотреть**, without editing controls unless they have this permission.

## Product contract
- Team kit stores palette, silhouette, sizes, number enabled/style/position, and front/back logo placement. This design is in `uniform_designs.team_id` (unique nullable FK); a personal design has `team_id = NULL`.
- `team.uniform.manage` is a separate contract permission listed in the existing team rights UI. Active owner-membership grants maximal access even for historical owner contracts without a backfilled capability. Delegated contracts require active, accepted membership and must pass contract assurance and time bounds.
- Team page displays **Настроить форму команды** for editors; active members may **Посмотреть форму команды** if a design exists. The shared modal loads `GET /uniforms/teams/{team}`; a server-provided `can_edit` flag determines whether the editor or a read-only summary is rendered.
- A read-only modal shows the same four-view SVG, name, two colors, cut, available sizes, number style, logo source/positions, and the viewer's number, with no form fields or save action. Hide the preview-size selector and number dragging while read-only.
- Team editors can change visual styling and the number's placement but cannot set an individual jersey number. The actual number (including 00) always belongs to `ContractMembership.jersey_number` and is assigned from the team's roster.
- The avatar generator must resolve team-owned UniformDesign and the player's team membership at request time. The AI payload uses the saved kit and the current roster number; a front logo only appears when configured in the kit. If there is no team design, retain existing team color/logo fallback.
- Team logo uploads remain private: team members and editors may view, outsiders may not. Never let a team-owned design be accessed via personal `/uniforms/projects/{id}` routes.
- All create/update/delete checks are server-side. Expired, revoked, pending, or non-assured contracts cannot edit. No privilege may be inferred from client-side modal state.

## Acceptance
- Owner can create and update one team kit; no personal-library leak.
- Delegated accepted team member with permission can edit; other active players can view only.
- Contracts expired or revoked immediately remove edit permissions.
- Profile player team selector gets **Посмотреть** next to the team, and the modal never allows an unauthorized user to change number or design.
- AI player number uses team membership (zero formatted as 00); custom/team/no logo matches team design.
- PostgreSQL migration, PHP authorization/feature tests, Vue/Vite build and normal CI; merge and deploy to dev only after green checks.
