# Multiple sport roles in a team

## Separation of concerns

A team membership contains two independent dimensions:

- `access_level` and contract permissions describe administrative access;
- `sport_roles` describe participation in the sporting life of the team.

A creator can therefore have:

```text
access_level = owner
sport_roles = []
```

and still manage the team. Conversely, `sport_roles = [manager]` does not grant administrative permissions by itself.

## Supported roles

A membership can have any combination of:

- `player`;
- `coach`;
- `manager`.

Examples:

```text
[player]
[player, coach]
[player, manager]
[coach, manager]
[]
```

Captain and default-starter flags are valid only when `player` is present.

## Compatibility column

`member_type` remains temporarily as a compatibility field for existing queries and data.

Its value is selected using this priority:

1. `player`;
2. `coach`;
3. `manager`;
4. `null` when there are no sport roles.

New domain code should use:

- `hasSportRole()`;
- `isPlayingMember()`;
- `sportRoleValues()`;
- `sportRoles()`.

## Creation flow

When a user creates a team:

- an accepted owner membership is always created;
- sport roles are selected independently;
- selecting no sport role is valid;
- owner rights do not depend on the selected roles;
- a playing creator is synchronized into each configured sport roster.

## Editing flow

A user with `team.roles.manage` permission can edit all sport roles of an accepted active member. The team creator always has this permission through creator access.

The editor includes the owner, including an owner with no sport roles.

## Member removal hierarchy

The permission `team.members.remove` is necessary but is not sufficient on its own. A non-owner can remove only a member with a strictly lower administrative access level.

The hierarchy is:

```text
owner > responsible > captain > coach > player
```

Consequences:

- the owner membership cannot be removed;
- a member cannot remove themself;
- an active captain cannot be removed until another captain is appointed;
- equal administrative levels cannot remove each other;
- a lower level cannot remove a higher level;
- the team creator and a system administrator may remove any lower-level non-captain member, but never the owner membership.

Sport roles do not affect this hierarchy. A sporting manager without administrative access remains subject to their contract `access_level`.

## Game roster behavior

Only memberships containing `player` can become game roster entries. Additional roles do not prevent participation:

```text
[player, coach]
```

is still a playing membership.

A membership without `player` is excluded from game snapshots.

## Legacy migration

Existing single `member_type` values are copied to `sport_roles`.

For older rows where `member_type` is null, the former access level is used:

- `coach` becomes `coach`;
- `responsible` becomes `manager`;
- `owner`, `captain`, and `player` become `player` to preserve the former game-roster behavior.
