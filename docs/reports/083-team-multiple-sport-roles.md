# Feature 083 — multiple sport roles for team members

## Goal

Allow the team creator and other members to have zero, one, or several sporting roles independently from administrative access.

## Implemented

- added `sport_roles` JSONB field to team memberships;
- migrated existing single roles and legacy null roles;
- kept `member_type` as a compatibility primary role;
- added model helpers for checking and listing sport roles;
- allowed a creator to select several roles while creating a team;
- allowed creating an owner membership without any sport role;
- kept owner permissions independent from sporting participation;
- added a role editor for every accepted member, including the owner;
- added separate visible sections for coaches and managers;
- allowed one person to appear in several sporting sections;
- synchronized players into discipline rosters;
- stored invitation role in both compatibility and multiple-role fields;
- prevented pending invitations from entering sport rosters;
- changed game snapshot filtering to use the playing-role invariant;
- added feature tests for an owner without roles and multiple-role combinations.

## Main invariants

- `sport_roles` never grant administrative permissions;
- the creator can manage roles even when `sport_roles` is empty;
- `manager` is a sporting/team-function role, not an authorization role;
- only a membership containing `player` can be captain, default starter, or game participant;
- adding coach or manager to a player does not remove the player from game eligibility;
- accepted active membership is required before synchronization into sport rosters.

## Changed interfaces

### Team creation

The creator can select:

- player;
- coach;
- manager;
- any combination;
- no role.

Captain and default-starter options are applied only when player is selected.

### Team page

The page now contains:

- trainers section;
- managers section;
- sport-role editor for all accepted members;
- owner role editor even when the owner has no sport roles.

## Automated coverage

`TeamMultipleSportRolesTest` covers:

- owner creation without sport roles;
- owner access to role editing without manager role;
- adding player, coach, and manager roles later;
- selecting player and coach during creation;
- captain and starter restrictions for non-players;
- synchronization into discipline rosters.

## Verification required

- run the new feature test;
- run `TeamRosterAndInvitationsTest`;
- run `GameLineupAndLifecycleTest`;
- run the complete PHP suite;
- compile Blade and frontend assets;
- manually verify creation and role editing in the browser.
