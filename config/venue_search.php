<?php

return [
    'store' => env('VENUE_SEARCH_CACHE_STORE'),
    'index_ttl_seconds' => (int) env('VENUE_SEARCH_INDEX_TTL', 3600),
    'result_ttl_seconds' => (int) env('VENUE_SEARCH_RESULT_TTL', 60),
];
