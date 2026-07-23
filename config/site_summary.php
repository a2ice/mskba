<?php

return [
    'cache_store' => env('SITE_SUMMARY_CACHE_STORE'),
    'cache_ttl_seconds' => (int) env('SITE_SUMMARY_CACHE_TTL_SECONDS', 300),

    'presence_store' => env('SITE_SUMMARY_PRESENCE_STORE', env('CACHE_STORE', 'database')),
    'presence_redis_connection' => env('SITE_SUMMARY_REDIS_CONNECTION', 'cache'),
    'presence_window_seconds' => (int) env('SITE_SUMMARY_PRESENCE_WINDOW_SECONDS', 120),
    'heartbeat_interval_seconds' => (int) env('SITE_SUMMARY_HEARTBEAT_INTERVAL_SECONDS', 45),
];
