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
    ],
];
