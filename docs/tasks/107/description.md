# 107 — Operational permissions for creating events and tournaments

## Goal

Restrict creation of events and tournaments with operational permissions that are granted automatically after the user gets a verified contact.

## Rules

- `event.create` — creation of events (game, game training, training).
- `tournament.create` — creation of tournaments.
- Both permissions are default-deny when no explicit snapshot exists.
- Once a canonical user identity has at least one verified contact, missing `event.create` and `tournament.create` snapshots are granted automatically.
- Automatic grant never overwrites an existing explicit `false` value set by an administrator.
- Existing users with verified contacts are backfilled by migration.
- Event and tournament create/store endpoints enforce the corresponding permission server-side.
- If a user lacks the permission because there is no verified contact, creation redirects into the existing contact-confirmation flow and remembers the intended create URL.
- After successful contact confirmation the user is returned to the intended create flow.
- If a verified user has the permission explicitly disabled, the UI explains that creation is restricted rather than asking to verify a contact again.
