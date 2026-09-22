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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        'face_validation_model' => env('OPENAI_FACE_VALIDATION_MODEL', 'gpt-5.6-luna'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-sunburst'),
        'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1536'),
        'image_quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
        'connect_timeout_seconds' => (int) env('OPENAI_CONNECT_TIMEOUT_SECONDS', 10),
        'validation_timeout_seconds' => (int) env('OPENAI_VALIDATION_TIMEOUT_SECONDS', 45),
        'generation_timeout_seconds' => (int) env('OPENAI_GENERATION_TIMEOUT_SECONDS', 180),
    ],

];
