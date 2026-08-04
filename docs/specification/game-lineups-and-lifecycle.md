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
starts_at / ends_at                 planned interval
actual_started_at / actual_ended_at factual interval
completed_at                        official confirmed completion
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
- UI visibility is supplemental and never replaces server validation.

## Temporary teams and mini-games

Temporary teams use the same `GameRosterEntry` snapshot. Their source is the confirmed participant of the parent event rather than permanent team membership. Starters and captain can be selected before factual start and are frozen in the same way.
