# Interface context separation

## Goal

Public presentation, owner management and platform administration are separate interface contexts.

A page must not silently become an administrative console because the current user has elevated permissions.

## Public presentation

A public `show` page presents the entity and may contain contextual actions that affect only the current user's own relationship with it.

Examples that belong on a public page:

- join, leave or change one's own participation status;
- accept or decline a personal invitation;
- vote, comment, rate, subscribe, share or report;
- open a map, discussion or related public entity;
- view score, roster, statistics and published results.

A public page must not contain controls that mutate the entity itself or another user's state.

## User management

Management pages are available to the creator, owner, responsible member or another user with explicit entity-level permissions.

Examples:

- edit title, description, logo and configuration;
- invite, remove or change other members;
- manage rosters, roles and contract permissions;
- assign responsible users;
- create sub-entities such as mini-games;
- cancel, complete or publish results.

Management is based only on entity ownership, membership, contracts and delegated permissions. A global administrator does not automatically receive management controls in the public/user context.

## Platform administration

Global administrative powers are exposed only under `/admin` routes and through dedicated admin controllers and views.

Administrative actions must be explicit and distinguishable from user management. Destructive or overriding actions should support an audit trail and, when appropriate, a reason.

An administrator viewing a public page behaves as an ordinary user. A "View on site" link may lead from the admin interface to the public page, but the public page must remain free of admin-only controls.

## Entity rules

### Teams

- `teams.show` is presentation only;
- general settings remain under team management;
- members, invitations, sporting roles, captaincy, rosters, permissions and removal belong to team management;
- accepting one's own invitation remains a personal contextual action outside management.

### Events

- `events.show` keeps personal participation and responsibility invitation responses;
- participant administration, responsibility assignment, mini-game creation, cancellation and result publishing belong to event management.

### Games and mini-games

- the public page displays teams, score, roster and statistics;
- score entry, live statistics, roster changes and lifecycle actions belong to a dedicated control context.

### Venues

- `venues.show` is presentation only;
- owner editing and schedule management stay in the account/management context;
- moderation and platform overrides stay under `/admin/venues`.

## Migration strategy

The separation is introduced incrementally by entity. Existing mutation endpoints keep their authorization checks while navigation and views move to dedicated contexts. Server-side authorization remains the source of truth throughout the migration.
