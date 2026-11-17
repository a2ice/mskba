<?php

return [
    'email' => env('SUPPORT_EMAIL', 'support@mskba.ru'),
    'faq_system_sections' => [
        'faq.welcome' => 'welcome',
        'faq.creation.venues' => 'venues',
        'faq.creation.events' => 'events',
        'faq.creation.teams' => 'teams',
        'faq.creation.tournaments' => 'tournaments',
        'faq.creation.sections' => 'sections',
        'faq.creation.coordination' => 'coordination',
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
