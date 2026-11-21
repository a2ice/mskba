<?php

return [
    'enabled' => env('UI_STUDIO_ENABLED', false),
    'host' => env('UI_STUDIO_HOST', 'ui.mskba.ru'),
    'user' => env('UI_STUDIO_USER'),
    'password_hash' => env('UI_STUDIO_PASSWORD_HASH'),
    'cases' => [
        'event-detail' => [
            'title' => 'Страница мероприятия',
            'issue' => 933,
            'revision' => 2,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['guest' => 'Гость', 'normal' => 'Авторизован, не участвует', 'confirmed' => 'Пойду', 'tentative' => 'Подумаю', 'booked' => 'Активная аренда', 'inactive' => 'Неактивная аренда', 'editing' => 'Редактирование сообщения'],
        ],
        'unified-chat' => [
            'title' => 'Единый чат — редактор сообщения',
            'issue' => 933,
            'revision' => 6,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Обычное', 'empty' => 'Пустое', 'editing' => 'Редактирование', 'disabled' => 'Недоступно'],
        ],
    ],
];
