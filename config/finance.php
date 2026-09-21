<?php

return [
    'wallet_owner_operations' => [
        'user' => (bool) env('FINANCE_WALLET_USER_OPERATIONS_ENABLED', true),
        'team' => (bool) env('FINANCE_WALLET_TEAM_OPERATIONS_ENABLED', false),
        'event' => (bool) env('FINANCE_WALLET_EVENT_OPERATIONS_ENABLED', false),
        'venue' => (bool) env('FINANCE_WALLET_VENUE_OPERATIONS_ENABLED', false),
    ],

    'user_transfers' => [
        'bonus_enabled' => (bool) env('FINANCE_USER_BONUS_TRANSFERS_ENABLED', true),
        'real_enabled' => (bool) env('FINANCE_USER_REAL_TRANSFERS_ENABLED', false),
    ],
];
