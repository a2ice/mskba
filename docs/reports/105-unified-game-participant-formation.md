# Feature 105 — unified standalone and tournament game participant formation

## Goal

Bring standalone games onto the same participant-formation principles already used by tournaments without coupling the Event aggregate to Tournament internals.

## Domain decisions

- Game remains the owner of final `GameSide` and `GameRosterEntry` snapshots.
- Recruitment is a preparation phase. A candidate/admission is not a GameSide.
- Standalone games support `preformed_teams` and `individual_draft`.
- A standalone game may be created with zero, one, or two known permanent teams.
- Initial team selection is consent-aware: a team the organizer is authorized to represent becomes an accepted `selection`; an eligible external team becomes a pending `invitation` and must be accepted by a team representative.
- One known team never creates a half-configured GameSide.
- Two selected teams are materialized immediately only when both are already accepted selections controlled by the organizer. Otherwise sides stay unconfirmed until both admissions are accepted and the organizer explicitly confirms the sides.
- `sides_confirmed_at` is the explicit confirmation flag. It may be cleared before factual start and then confirmed again.
- EventParticipant is synchronized from the final roster; it is not the source of the sports roster.
- Factual start is rejected until standalone sides are explicitly confirmed and normal lineup invariants pass.

## Admissions

`game_admissions` supports:

- application — candidate asks to participate;
- invitation — organizer invites a candidate;
- selection — organizer supplied a team while creating the game and is authorized to act for that team.

`team.game_participation.manage` is an authority to act **on behalf of a team**: submit an application, accept/decline an invitation, revoke participation, etc. It does not gate an organizer's ability to invite an unrelated eligible team.

A permanent active team can opt out of organizer invitations through `accepts_competition_invitations`. Opted-out teams are excluded from game/tournament invitation search and direct invitation requests are rejected. The opt-out does not prevent an authorized team representative from submitting the team's own application.

Team applications and invitation responses require the team creator or an active accepted team member with `team.game_participation.manage`. New public applications can be disabled independently; organizer invitations remain available for eligible teams. Admission writes use Event -> Game -> Admission/Candidate locking and re-check authorization after locks.

## Balanced formation

The scoring/distribution algorithm was extracted from `TournamentFormationService` into `App\Support\Basketball\BalancedTeamFormationEngine`.

Both Tournament and standalone individual-draft preview now use the same formula version, weights, neutral missing-value handling, objective-assessment confidence adjustment, seeded tie breaking, low-coverage distribution and position balancing.

Standalone preview uses a pool fingerprint. Apply rejects stale previews and requires every accepted canonical player exactly once across exactly two sides.

## UI

- Event creation exposes recruitment mode and application toggle.
- Team mode supports zero/one/two initial teams and only shows teams that currently allow competition invitations.
- Public standalone game panel lets an eligible player/team representative apply and answer invitations.
- Management panel supports invitations, application decisions, application toggle, team confirmation/unconfirmation, balanced player formation, and pre-start game configuration.
- Team settings expose whether organizers may invite the team to games and tournaments.
- Balanced drag/drop rendering is shared by Tournament and standalone game management through `balanced-formation.js`.

## Lifecycle

- Sides and main game settings are mutable only before factual start.
- Changing side sizes while sides are confirmed requires explicit unconfirmation first.
- Unconfirmation removes the Game roster/sides snapshot but preserves admissions; former roster EventParticipants are marked left except the organizer.
- After actual start, sides, roster and main configuration are immutable through recruitment endpoints.

## Verification

Feature tests cover one-known-team creation, external-team invitation consent, team invitation opt-out, self-application despite opt-out, application/invitation authorization, delegated team permission, application toggle, confirmation/unconfirmation, EventParticipant synchronization, stale balanced preview, deterministic preview, final materialization and lifecycle locks.
