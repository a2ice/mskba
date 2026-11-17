<?php

return [
    // Stored only in ephemeral Redis/cache; never in permanent audit tables.
    'cache_store' => env('OPERATOR_ACTIVITY_CACHE_STORE', 'redis'),
    'online_seconds' => (int) env('OPERATOR_ACTIVITY_ONLINE_SECONDS', 150),
    'heartbeat_seconds' => (int) env('OPERATOR_ACTIVITY_HEARTBEAT_SECONDS', 60),
];
