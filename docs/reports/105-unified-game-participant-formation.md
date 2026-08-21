# Feature 105 — unified standalone and tournament game participant formation

## Goal

Bring standalone games onto the same participant-formation principles already used by tournaments without coupling the Event aggregate to Tournament internals.

## Domain decisions

- Game remains the owner of final `GameSide` and `GameRosterEntry` snapshots.
- Recruitment is a preparation phase. A candidate/admission is not a GameSide.
- Standalone games support `preformed_teams` and `individual_draft`.
- A standalone game may be created with zero, one, or two known permanent teams.
- One known team is stored as an accepted selection candidate; no half-configured GameSide is created.
- Two known teams are materialized immediately and marked as confirmed sides.
- `sides_confirmed_at` is the explicit confirmation flag. It may be cleared before factual start and then confirmed again.
- EventParticipant is synchronized from the final roster; it is not the source of the sports roster.
- Factual start is rejected until standalone sides are explicitly confirmed and normal lineup invariants pass.

## Admissions

`game_admissions` supports:

- application — candidate asks to participate;
- invitation — organizer invites a candidate;
- selection — organizer supplied a known team while creating the game.

Team applications/invitation responses require the team creator or an active accepted team member with `team.game_participation.manage`. New public applications can be disabled independently; organizer invitations remain available. Admission writes use Event -> Game -> Admission/Candidate locking and re-check authorization after locks.

## Balanced formation

The scoring/distribution algorithm was extracted from `TournamentFormationService` into `App\Support\Basketball\BalancedTeamFormationEngine`.

Both Tournament and standalone individual-draft preview now use the same formula version, weights, neutral missing-value handling, objective-assessment confidence adjustment, seeded tie breaking, low-coverage distribution and position balancing.

Standalone preview uses a pool fingerprint. Apply rejects stale previews and requires every accepted canonical player exactly once across exactly two sides.

## UI

- Event creation exposes recruitment mode and application toggle.
- Team mode supports zero/one/two initial teams.
- Public standalone game panel lets an eligible player/team representative apply and answer invitations.
- Management panel supports invitations, application decisions, application toggle, team confirmation/unconfirmation, balanced player formation, and pre-start game configuration.
- Balanced drag/drop rendering is shared by Tournament and standalone game management through `balanced-formation.js`.

## Lifecycle

- Sides and main game settings are mutable only before factual start.
- Changing side sizes while sides are confirmed requires explicit unconfirmation first.
- Unconfirmation removes the Game roster/sides snapshot but preserves admissions; former roster EventParticipants are marked left except the organizer.
- After actual start, sides, roster and main configuration are immutable through recruitment endpoints.

## Verification

Feature tests cover one-known-team creation, application/invitation authorization, delegated team permission, application toggle, confirmation/unconfirmation, EventParticipant synchronization, stale balanced preview, deterministic preview, final materialization and lifecycle locks.
