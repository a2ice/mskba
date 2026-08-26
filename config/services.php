<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'yandex_metrika' => [
        'id' => env('YANDEX_METRIKA_ID'),
    ],

    'social' => [
        'telegram_url' => env('TELEGRAM_COMMUNITY_URL', 'https://t.me/mskbaofficial'),
        'vk_url' => env('VK_COMMUNITY_URL', 'https://vk.ru/mskba_official'),
    ],

    'venue_rental_payment' => [
        'driver' => env('VENUE_RENTAL_PAYMENT_DRIVER', 'external_manual'),
        'merchant' => env('VENUE_RENTAL_PAYMENT_MERCHANT', 'mskba'),
    ],

];
