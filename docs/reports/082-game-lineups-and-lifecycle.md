# Feature 082 — game lineups and factual lifecycle

## Goal

Complete the game lifecycle introduced in feature 081 and add permanent team sport roles plus historical game lineup snapshots.

## Implemented

- permanent member type: player, coach, manager;
- permanent captain and default starter flags;
- validation that only players can be captain or starter;
- automatic replacement of the previous permanent captain;
- game lineup roles: starter and bench;
- game-specific captain for every side;
- copying permanent defaults into standalone game snapshots;
- exclusion of coaches and managers from game rosters;
- legacy membership compatibility (`member_type = null` is treated as player);
- editable game lineup before factual start;
- deterministic completion of legacy lineups at start;
- roster locking with `locked_at`;
- factual lifecycle enforcement for roster, score, statistics and confirmation;
- UI controls for team sport roles and game lineup management;
- feature tests for defaults, overrides, historical snapshots and lifecycle restrictions;
- architecture specification.

## Data changes

`contract_memberships`:

- `member_type` nullable string;
- `is_captain` boolean;
- `is_default_starter` boolean.

`game_roster_entries`:

- `lineup_role` string;
- `is_captain` boolean;
- `locked_at` timestamp with timezone.

## Main invariants

- access rights and sporting role are independent;
- a team has no more than one active permanent captain;
- a game side has exactly one captain when the game starts;
- starter count equals the configured side size;
- coaches and managers never become game roster entries;
- changing a permanent team does not rewrite an existing game snapshot;
- live writes are allowed only between factual start and factual end;
- game roster and lineup are immutable after factual start.

## Automated coverage

Added `GameLineupAndLifecycleTest` covering:

- copying captain and starter defaults;
- excluding non-players;
- historical snapshot independence;
- game-specific override;
- locking at factual start;
- blocking changes after start;
- blocking score before start and after end;
- blocking confirmation before factual end;
- permanent captain replacement;
- invalid coach captain/starter assignment.

## Remaining verification before merge

- run the complete PHP test suite in the project runtime;
- run frontend build and static checks;
- verify existing game workflow tests after mandatory factual lifecycle changes;
- manually verify team and game pages in the deployed environment;
- review migration rollback on PostgreSQL.
