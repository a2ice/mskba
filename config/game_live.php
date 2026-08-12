<?php

return [
    'presence_store' => env('GAME_LIVE_PRESENCE_STORE', env('CACHE_STORE', 'database')),
    'presence_redis_connection' => env('GAME_LIVE_PRESENCE_REDIS_CONNECTION', 'cache'),
    'presence_window_seconds' => (int) env('GAME_LIVE_PRESENCE_WINDOW_SECONDS', 120),
    'heartbeat_interval_seconds' => (int) env('GAME_LIVE_HEARTBEAT_INTERVAL_SECONDS', 45),
    'history_session_gap_seconds' => (int) env('GAME_LIVE_HISTORY_SESSION_GAP_SECONDS', 180),
];
