<?php

return [
    'app_id' => env('VK_ID_APP_ID'),
    'redirect_uri' => env('VK_ID_REDIRECT_URI'),
    'authorize_url' => env('VK_ID_AUTHORIZE_URL', 'https://id.vk.ru/authorize'),
    'token_url' => env('VK_ID_TOKEN_URL', 'https://id.vk.ru/oauth2/auth'),
    'user_info_url' => env('VK_ID_USER_INFO_URL', 'https://id.vk.ru/oauth2/user_info'),
    'flow_ttl' => (int) env('VK_ID_FLOW_TTL', 600),
];
