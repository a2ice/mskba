<?php

return [
    'enabled' => env('UI_STUDIO_ENABLED', false),
    'issuer_enabled' => env('UI_STUDIO_BRIDGE_ENABLED', false),
    'bridge_secret' => env('UI_STUDIO_BRIDGE_SECRET'),
    'parent_origins' => ['https://mskba.ru', 'https://dev.mskba.ru'],
    'host' => env('UI_STUDIO_HOST', 'ui.mskba.ru'),
    'user' => env('UI_STUDIO_USER'),
    'password_hash' => env('UI_STUDIO_PASSWORD_HASH'),
    'cases' => [
        'unified-chat' => [
            'title' => 'Единый чат — редактор сообщения',
            'issue' => 933,
            'revision' => 3,
            'sources' => [
                'resources/views/ui-studio/cases/unified-chat.blade.php',
                'resources/views/ui-studio/cases/unified-chat-candidate.blade.php',
                'resources/js/chat-composer-actions.js',
            ],
            'kit_changes' => [
                ['id' => 'chat-composer-focus', 'title' => 'Фокус редактора сообщений', 'description' => 'Единая рамка фокуса для composer без двойного outline.'],
                ['id' => 'chat-actions-hit-area', 'title' => 'Управление сообщениями', 'description' => 'Компактные видимые кнопки с увеличенной touch-областью.'],
                ['id' => 'chat-date-groups', 'title' => 'Группировка чата по датам', 'description' => 'Повторно используемый маркер дня в ленте сообщений.'],
            ],
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Обычное', 'empty' => 'Пустое', 'editing' => 'Редактирование', 'disabled' => 'Недоступно'],
        ],
    ],
];
