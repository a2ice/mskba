<?php

return [
    'email' => env('SUPPORT_EMAIL', 'support@mskba.ru'),
    // Staging keeps submitted questions but never sends external mail by default.
    'deliver_email' => env('SUPPORT_EMAIL_DELIVERY_ENABLED', env('APP_ENV') === 'production'),
    // FAQ does not yet have a persisted category tree. These virtual sections
    // group published FAQ materials by stable system keys or editor-managed tags.
    'faq_sections' => [
        'welcome' => ['label' => 'Первые шаги', 'keys' => ['faq.welcome']],
        'venues' => ['label' => 'Площадки', 'keys' => ['faq.creation.venues']],
        'events' => ['label' => 'Мероприятия', 'keys' => ['faq.creation.events']],
        'teams' => ['label' => 'Команды', 'keys' => ['faq.creation.teams']],
        'tournaments' => ['label' => 'Турниры', 'keys' => ['faq.creation.tournaments']],
        'sections' => ['label' => 'Секции и тренировки', 'keys' => ['faq.creation.sections']],
        'coordination' => ['label' => 'Координация', 'keys' => ['faq.creation.coordination']],
        'account' => ['label' => 'Аккаунт и профиль', 'keys' => []],
        'other' => ['label' => 'Другие вопросы', 'keys' => []],
    ],
    'question_topics' => [
        'venues' => 'Площадки и бронирование',
        'events' => 'Игры и мероприятия',
        'teams' => 'Команды',
        'tournaments' => 'Турниры',
        'account' => 'Аккаунт и безопасность',
        'payments' => 'Оплата и бонусы',
        'technical' => 'Техническая проблема',
        'other' => 'Другой вопрос',
    ],
];
