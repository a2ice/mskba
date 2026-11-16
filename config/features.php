<?php

return [
    // Temporary manual QA entry to personal chats. Not a general messenger feature.
    // Override explicitly with FEATURE_DIRECT_MESSAGE_TEST_ENTRY in any environment.
    'direct_messages' => [
        'test_entry' => (bool) env('FEATURE_DIRECT_MESSAGE_TEST_ENTRY', in_array(
            parse_url((string) env('APP_URL', ''), PHP_URL_HOST),
            ['localhost', '127.0.0.1', 'dev.mskba.ru'], true,
        )),
    ],
    'sports_sections' => [
        'enabled' => (bool) env('FEATURE_SPORTS_SECTIONS', true),
    ],
    'venue_rental' => [
        // Enable only after the special terms regression suite and review.
        'special_terms' => (bool) env('FEATURE_VENUE_RENTAL_SPECIAL_TERMS', false),
        // New contracts only; enabled on staging for acceptance, never on production by default.
        'external_holds' => (bool) env('FEATURE_VENUE_RENTAL_EXTERNAL_HOLDS', env('APP_ENV') === 'staging'),
        'invoices' => (bool) env('FEATURE_VENUE_RENTAL_INVOICES', env('APP_ENV') === 'staging'),
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
