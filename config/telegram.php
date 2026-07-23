<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'bot_domain' => env('TELEGRAM_BOT_DOMAIN'),
    'main_chat_id' => env('TELEGRAM_MAIN_CHAT_ID'),
    'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    'api_ip' => env('TELEGRAM_API_IP'),
    'http_proxy' => env('TELEGRAM_HTTP_PROXY'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
];
