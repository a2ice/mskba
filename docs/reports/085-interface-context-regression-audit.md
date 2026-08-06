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
- [x] User update route rejects the administrative `status` field.
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
- [x] Result photo upload, description, participant tagging, and deletion were restored in management context.
- [x] Result photo editor selectors match `event-show.js`.
- [x] Mini-game creation sends required side rosters, side sizes, names, and scoring type.
- [ ] Browser-check participant predictive search and group movement.
- [ ] Browser-check responsibility forms and validation feedback.
- [ ] Browser-check photo upload, tag placement, save, and delete.
- [ ] Browser-check mini-game creation with valid and invalid rosters.

## Games

- [x] Public game page receives no management permissions.
- [x] Dedicated `GameControlController` renders `events.game` with effective permissions and statistics fields.
- [x] `/events/{event}/game` has one canonical GET route handled by `GameControlController`.
- [x] Statistics form selectors match `event-show.js`.
- [x] Mini-game schedule selectors match `event-show.js`.
- [x] Confirm the canonical control route locally with `php artisan route:list --name=events.game.manage`.
- [ ] Browser-check calculated score, manual score override, save, and complete flow.
- [ ] Browser-check roster form and lifecycle controls.

## Coordination

- [x] Public page keeps voting, vote changes, suggestions, and result viewing.
- [x] Closing, deciding, and cancelling are available only in `/coordination/{coordination}/management`.
- [x] Management route performs server-side `CoordinationAccess` authorization.
- [x] Event creation and applying a coordinated move remain contextual result actions by design.
- [ ] Browser-check vote, suggestion, close, decision, and cancel transitions.

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

## Required local checks before merge

```bash
php artisan route:list --name=teams
php artisan route:list --name=events
php artisan route:list --name=coordination
php artisan view:cache
php artisan test tests/Feature/Team/TeamOwnerSportRoleProtectionTest.php
php artisan test tests/Feature/Event/EventInterfaceContextSeparationTest.php
php artisan test tests/Feature/Coordination/CoordinationInterfaceContextSeparationTest.php
php artisan test tests/Feature/Team
php artisan test tests/Feature/Event
php artisan test tests/Feature/Coordination
npm run build
```

Automated tests and frontend build were not executed while preparing this report.
