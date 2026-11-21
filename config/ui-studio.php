<?php

return [
    'enabled' => env('UI_STUDIO_ENABLED', false),
    'host' => env('UI_STUDIO_HOST', 'ui.mskba.ru'),
    'user' => env('UI_STUDIO_USER'),
    'password_hash' => env('UI_STUDIO_PASSWORD_HASH'),
    'cases' => [
        'unified-chat' => [
            'title' => 'Единый чат — редактор сообщения',
            'issue' => 933,
            'revision' => 1,
            'status' => 'Пилот · не принят',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Обычное', 'empty' => 'Пустое', 'disabled' => 'Недоступно'],
        ],
    ],
];
