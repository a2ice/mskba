# Team roles, game lineups and factual lifecycle

## Permanent team membership

The existing `ContractMembership` remains the source of team membership and access rights.
Sport-specific fields do not replace `access_level`:

- `access_level` controls management permissions;
- `member_type` describes the sporting function: `player`, `coach`, `manager`;
- `is_captain` marks the permanent team captain;
- `is_default_starter` marks a player selected for the default starting lineup.

A membership created before sport roles were introduced has `member_type = null` and is treated as a player for backward compatibility.
Only a player can be captain or default starter. A permanent team can have at most one active captain. Assigning a new captain demotes the previous captain without removing their membership.

Examples:

```text
access_level = owner
member_type = player
is_captain = true
```

```text
access_level = responsible
member_type = coach
is_captain = false
```

## Game roster snapshot

`GameRosterEntry` is a historical snapshot and is independent from later team changes:

- `lineup_role`: `starter` or `bench`;
- `is_captain`: captain of the specific game side;
- `locked_at`: moment when the roster was frozen by factual game start;
- source membership or event participant remains available for traceability.

When a standalone game is created, only players are copied from permanent teams. Permanent captain and default starter flags are copied as initial values. Coaches and managers are excluded.

Before factual start the responsible user can override starters and captain for each side. The override changes only the game snapshot and does not modify the permanent team.

At factual start:

1. each side must have enough selected players for its format;
2. the number of starters must equal the configured side size;
3. legacy games with missing starters are deterministically completed by roster order;
4. each side has exactly one captain; a missing captain is selected deterministically from starters;
5. every selected roster entry receives `locked_at`;
6. roster, lineup and game parameters become immutable.

## Planned and factual time

```text
Event.starts_at / Event.ends_at                     общий организационный интервал
Game.scheduled_starts_at / Game.scheduled_ends_at   внутренний необязательный слот
Game.actual_started_at / Game.actual_ended_at       фактический интервал игры
Game.completed_at                                   официальное подтверждение результата
```

Factual lifecycle:

```text
planned
  -> actual start
  -> live score and statistics entry
  -> actual end
  -> review and confirmation
  -> completed
```

Server-side rules:

- roster, lineup and game parameters are editable only before factual start;
- live score and statistics are writable only after factual start and before factual end;
- result confirmation is available only after factual end;
- cancellation and deletion are blocked after factual start;
- UI visibility and HTTP middleware are early guards only and never replace
  application-service validation;
- the application service checks the factual phase after locking the parent
  event, game and game sides, so concurrent requests cannot bypass the
  lifecycle invariant.

## Temporary teams and mini-games

Temporary teams use the same `GameRosterEntry` snapshot. Their source is the confirmed participant of the parent event rather than permanent team membership. Starters and captain can be selected before factual start and are frozen in the same way.

## Local demo data

Browser acceptance scenarios are created separately from the production-safe
base seeder:

```bash
php artisan db:seed --class=GameLifecycleDemoSeeder
```

When the host PHP does not have the Redis extension, use
`CACHE_STORE=array` for this command or run it inside the `phpfpm` container.
The seeder is idempotent, resets its stable `demo-*` records to known states and
is explicitly restricted to `local/testing`. It provides permanent teams and
games as well as a game training with live and review-stage mini-games.

Tournament demo data is intentionally excluded until the tournament domain
model is implemented; a tournament must not be represented as a specially
named event.

## Permanent team sport profile

A permanent team stores one or more supported sport profiles independently
from a particular game's scoring and format settings:

- streetball;
- basketball.

These values are catalog characteristics and do not limit the total roster: a
streetball team can keep substitutes and can support basketball at the same
time. A particular game still owns the factual `side_a_size`, `side_b_size` and
`scoring_type`, so historical games and custom formats do not change when the
team profiles are edited.

Each profile owns an independent permanent lineup projection in
`team_sport_lineup_members`. Every accepted active player is assigned either
to `starter` or `reserve`; ordering is persisted for drag-and-drop. Streetball
requires three starters and basketball five. If the team has enough active
players, saving fewer starters is rejected. If the total player count is below
the requirement, the partial arrangement is allowed and the team is presented
as `Неполный состав`. New accepted players enter the reserve of every current
profile. A new game copies the starter assignment matching its scoring type;
legacy `is_default_starter` remains only as a fallback for historical records.

Team membership is invitation based. A new membership contract is inactive
and has `invitation_status=pending` until the invited user accepts it. Declined
invitations remain inactive. Contract permissions use the existing
`contract_permissions` table and atomic permissions for roster, invitations,
roles and permission delegation. An active, non-blocked creator has every team
permission; other accepted members receive only permissions stored in their
active contract. Team mutations use a stable lock order: team, sport profile
or membership, then lineup rows.
