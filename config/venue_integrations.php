<?php

return [
    // Explicit global kill switch until Task 306 secure adapters and Task 308
    // bounded execution/observability pass their separate acceptance gates.
    // Neither an admin-enabled source nor a saved credential can initiate a
    // network request from this task's code.
    'live_source_execution_enabled' => false,

    // A separate, single-click, read-only diagnostic. Off on all environments
    // except on the isolated staging environment. It never enables polling or booking.
    // Staging only: public display of stored FindSport observations on
    // the linked Basketpoint court page. Never runs external requests,
    // overrides booking policies, or enables checkouts.
    'findsport_public_snapshot_enabled' => (bool) env(
        'VENUE_INTEGRATIONS_FINDSPORT_PUBLIC_SNAPSHOT_ENABLED',
        env('APP_ENV') === 'staging',
    ),

    'findsport_preview_enabled' => (bool) env(
        'VENUE_INTEGRATIONS_FINDSPORT_PREVIEW_ENABLED',
        env('APP_ENV') === 'staging',
    ),
];
