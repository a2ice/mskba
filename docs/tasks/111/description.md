# Task 111 — Telegram participation follows event/game recruitment rules

## Goal

Make Telegram event publication actions use the same participation model as the web UI.

## Rules

- Standalone game + individual draft: `Пойду` creates a pending `GameAdmission` application instead of directly confirming an `EventParticipant`.
- Repeated `Пойду` is idempotent when an application is already pending or accepted.
- `Не пойду` revokes/declines the active individual admission, marks an event participant as left and excludes an active roster entry while preserving historical game statistics.
- Standalone game + preformed teams: personal `Пойду / Не пойду` buttons are not shown.
- Training and game training keep the existing event-participation semantics.
- Individual late join remains available while the game is in progress if applications are enabled.
- Public cancelled events remain viewable from the Telegram Mini App destination and display the cancelled state instead of returning 404.
- Telegram publication includes game format, recruitment mode, player-pool status, teams/score when available, and current activity status.
