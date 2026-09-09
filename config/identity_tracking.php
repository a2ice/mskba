<?php

return [
    'activity_store' => env('IDENTITY_FINGERPRINT_ACTIVITY_STORE', env('CACHE_STORE', 'database')),
    'activity_write_interval_seconds' => (int) env('IDENTITY_FINGERPRINT_ACTIVITY_WRITE_INTERVAL_SECONDS', 600),
    'fingerprint_retention_days' => (int) env('IDENTITY_FINGERPRINT_RETENTION_DAYS', 90),
    'prune_batch_size' => (int) env('TRACKING_PRUNE_BATCH_SIZE', 1000),
    'prune_max_batches' => (int) env('TRACKING_PRUNE_MAX_BATCHES', 100),
];
