<?php

return [
    'venue_rental' => [
        'rental_flow' => (bool) env('FEATURE_VENUE_RENTAL_FLOW', false),
        'coordination' => (bool) env('FEATURE_VENUE_RENTAL_COORDINATION', false),
        'external_payment' => (bool) env('FEATURE_VENUE_RENTAL_EXTERNAL_PAYMENT', false),
        'attendance_v2' => (bool) env('FEATURE_VENUE_RENTAL_ATTENDANCE_V2', false),
        'conversations' => (bool) env('FEATURE_VENUE_RENTAL_CONVERSATIONS', false),
        'booking_events' => (bool) env('FEATURE_VENUE_RENTAL_BOOKING_EVENTS', false),
        'contributions' => (bool) env('FEATURE_VENUE_RENTAL_CONTRIBUTIONS', false),
        'portal' => (bool) env('FEATURE_VENUE_RENTAL_PORTAL', false),
        'payment_port' => (bool) env('FEATURE_VENUE_RENTAL_PAYMENT_PORT', false),
    ],
    'venue_rental_rollout' => [
        'mode' => env('VENUE_RENTAL_ROLLOUT_MODE', 'all'),
        'percentage' => (int) env('VENUE_RENTAL_ROLLOUT_PERCENTAGE', 100),
        'user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('VENUE_RENTAL_ROLLOUT_USER_IDS', ''))))),
        'venue_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('VENUE_RENTAL_ROLLOUT_VENUE_IDS', ''))))),
        'contract_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('VENUE_RENTAL_ROLLOUT_CONTRACT_IDS', ''))))),
    ],
];
