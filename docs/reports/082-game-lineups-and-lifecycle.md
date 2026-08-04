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
- explicit rejection when a non-player is submitted through the old roster editor;
- legacy membership compatibility (`member_type = null` is treated as player);
- editable game lineup before factual start;
- authorization of lineup changes for both standalone and child games;
- deterministic completion of legacy lineups at start;
- roster locking with `locked_at`;
- factual lifecycle enforcement for roster, score, statistics and confirmation;
- UI controls for team sport roles and game lineup management;
- feature tests for defaults, overrides, historical snapshots and lifecycle restrictions;
- test-only compatibility adapter for workflow tests written before factual lifecycle actions;
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
- game roster and lineup are immutable after factual start;
- an unrelated authenticated user cannot edit a standalone game lineup.

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

The existing `GameAndTeamWorkflowTest` is kept compatible through a test-only lifecycle phase adapter in `tests/TestCase.php`. Production requests are not bypassed.

## Pull request

Draft PR: `#3 Feature 082: game lineups and lifecycle`.

The repository did not start a GitHub Actions workflow for the draft PR, so no remote CI result is available.

## Remaining verification before merge

- run the complete PHP test suite in the project runtime;
- run frontend build and static checks;
- manually verify team and game pages in the deployed environment;
- review migration rollback on PostgreSQL.
