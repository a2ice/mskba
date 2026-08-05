# Feature 085 — interface context regression audit

## Scope

The audit verifies that splitting public presentation, user management, game control, and platform administration does not leave broken or duplicated interactions.

## Contract matrix

For each moved action verify:

1. Blade element exists only in the intended context.
2. Required `data-*` attributes match the JavaScript initializer.
3. The initializer is loaded by the active Vite entrypoint.
4. URL and HTTP method match the named Laravel route.
5. Server-side access does not rely on hidden UI.
6. Success, validation, authorization, and network errors have visible feedback.
7. Public pages have no dead controls or hidden management forms.

## Teams

- [x] Public page has no `data-team-management` root.
- [x] Public roster is read-only.
- [x] Management page has the `data-team-management` root expected by `team-management.js`.
- [x] Roster groups expose update URL, sport type, size limit, editability, zones, counters, and save trigger.
- [x] Player partial receives permission/removal/current-user values in both public and management contexts.
- [x] Captain action uses the expected `data-captain-url` contract.
- [x] Permission forms expose `data-team-permissions-form` and update URL.
- [x] Removal action exposes `data-team-member-remove-url`.
- [x] Invitation search and submission selectors match `team-management.js`.
- [x] Global administrator receives no implicit user-management access.
- [ ] Browser-check drag/drop and mobile move button.
- [ ] Browser-check modal open/close integration.

## Events

- [x] Public page keeps personal participation actions.
- [x] Public page receives an empty management permission collection.
- [x] Management page has the `data-event-participant-manager` root expected by `event-show.js`.
- [x] Candidate URL, search input, hidden user ID, result list, status, message, and submit trigger are present.
- [x] Participant groups and status forms expose the selectors used by AJAX updates.
- [x] Management URL is shown only when actor-based user permissions allow it.
- [x] Global administrator receives no implicit event-management access.
- [ ] Browser-check participant predictive search and group movement.
- [ ] Browser-check responsibility forms and validation feedback.
- [ ] Browser-check result photo editing remains available only in management context where intended.

## Games

- [x] Public game page receives no management permissions.
- [x] Control page remains `events.game.manage` and keeps score/statistics/roster endpoints.
- [x] Statistics form selectors match `event-show.js`.
- [x] Mini-game schedule selectors match `event-show.js`.
- [ ] Browser-check calculated score, manual score override, save, and complete flow.
- [ ] Browser-check roster form and lifecycle controls.

## Venues

- [x] Public page exposes one transition to account management instead of moderation/admin links.
- [x] Superadmin has no implicit account schedule access.
- [x] Platform moderation remains under `/admin/venues`.
- [ ] Browser-check account edit, photos, schedule, and moderation submission.

## Admin

- [x] Team list and detail pages use admin routes and admin templates.
- [x] Event list and detail pages use admin routes and admin templates.
- [x] Admin detail pages do not reuse owner/organizer management forms.
- [ ] Add explicit force-action endpoints only when an administrative use case requires them.

## Required automated checks before merge

- Team public/management separation.
- Event public/management separation.
- Global admin denied from user-management routes without domain membership/responsibility.
- Admin routes denied to regular users.
- Personal participation actions remain available on public event pages.
- Named route generation for all new management and admin links.

Automated tests were not executed while preparing this report.
