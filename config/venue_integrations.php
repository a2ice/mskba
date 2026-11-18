<?php

return [
    // Explicit global kill switch until Task 306 secure adapters and Task 308
    // bounded execution/observability pass their separate acceptance gates.
    // Neither an admin-enabled source nor a saved credential can initiate a
    // network request from this task's code.
    'live_source_execution_enabled' => false,

    // FindSport read-only preview and explicit native import are available on
    // staging and production. The ordinary integration execution engine above
    // stays disabled. Public refresh is limited to an already-applied, verified
    // source, at most once every 300 seconds per source across all visitors.
    // Each feature has its own emergency off switch in the server's .env.
    'public_schedule_refresh_enabled' => (bool) env(
        'VENUE_INTEGRATIONS_PUBLIC_SCHEDULE_REFRESH_ENABLED',
        in_array(env('APP_ENV'), ['staging', 'production'], true),
    ),

    'apply_findsport_native_enabled' => (bool) env(
        'VENUE_INTEGRATIONS_APPLY_FINDSPORT_NATIVE_ENABLED',
        in_array(env('APP_ENV'), ['staging', 'production'], true),
    ),

    'findsport_preview_enabled' => (bool) env(
        'VENUE_INTEGRATIONS_FINDSPORT_PREVIEW_ENABLED',
        in_array(env('APP_ENV'), ['staging', 'production'], true),
    ),
];
