<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'bot_domain' => env('TELEGRAM_BOT_DOMAIN'),
    'main_chat_id' => env('TELEGRAM_MAIN_CHAT_ID'),
    'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
];
