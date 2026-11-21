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
            'revision' => 4,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['guest' => 'Гость', 'normal' => 'Авторизован, не участвует', 'confirmed' => 'Пойду', 'tentative' => 'Подумаю', 'booked' => 'Активная аренда', 'inactive' => 'Неактивная аренда', 'editing' => 'Редактирование сообщения', 'report' => 'Опубликованный отчёт', 'report-empty' => 'Завершено, без отчёта', 'report-owner' => 'Завершено, организатор'],
        ],
        'venue-map' => [
            'title' => 'Карта площадки — общий компонент',
            'issue' => 933,
            'revision' => 1,
            'status' => 'На визуальной приёмке',
            'templates' => ['mskba_app'],
            'states' => ['normal' => 'Со страницы площадки', 'event' => 'Со страницы мероприятия'],
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
