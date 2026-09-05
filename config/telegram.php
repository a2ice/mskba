<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'bot_domain' => env('TELEGRAM_BOT_DOMAIN'),
    'main_chat_id' => env('TELEGRAM_MAIN_CHAT_ID'),
    'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
    'login_widget_max_age' => env('TELEGRAM_LOGIN_WIDGET_MAX_AGE', 600),
    'user_duplicate_merge_proof_ttl' => (int) env('TELEGRAM_USER_DUPLICATE_MERGE_PROOF_TTL', 600),
    'bot_login_ttl' => (int) env('TELEGRAM_BOT_LOGIN_TTL', 120),
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    'api_ip' => env('TELEGRAM_API_IP'),
    'http_proxy' => env('TELEGRAM_HTTP_PROXY'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'updates_transport' => env('TELEGRAM_UPDATES_TRANSPORT', 'webhook'),
    'polling_timeout' => (int) env('TELEGRAM_POLLING_TIMEOUT', 25),
    'polling_retry_delay' => (int) env('TELEGRAM_POLLING_RETRY_DELAY', 5),
    'queue_connection' => env('TELEGRAM_QUEUE_CONNECTION', 'redis'),
    'queues' => [
        'inbound' => env('TELEGRAM_QUEUE_INBOUND', 'telegram-inbound'),
        'background' => env('TELEGRAM_QUEUE_BACKGROUND', 'telegram-background'),
    ],
    'reactions' => [
        'positive' => [
            '❤', '👍', '🔥', '🥰', '👏', '🎉', '🤩', '👌', '😍', '❤‍🔥',
            '💯', '⚡', '🏆', '🍾', '💋', '🙏', '😇', '🤝', '🤗', '🫡',
            '💘', '😘', '😎',
        ],
        'negative' => [
            '👎', '🤬', '🤮', '💩', '💔', '🖕', '😡',
        ],
    ],
];
