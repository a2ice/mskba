<?php

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'timeout_seconds' => (int) env('GOTENBERG_TIMEOUT_SECONDS', 30),
    ],

    'qr' => [
        'binary' => env('QR_CODE_BINARY', 'qrencode'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted built-in templates
    |--------------------------------------------------------------------------
    |
    | These Blade views live in the repository and are trusted application
    | code. Future administrator-editable templates must use a separate safe
    | placeholder renderer and must never execute arbitrary Blade/PHP.
    |
    */
    'registry' => [
        'acquisition.flyer.a4' => [
            'driver' => 'blade',
            'view' => 'theme::documents.acquisition-flyer-a4',
            'channels' => ['flyer'],
            'assets' => [
                'logo' => 'images/logo-header-cropped.png',
                'hero_image' => 'images/acquisition/flyer-basketball-fire.png',
            ],
            'fields' => [
                'eyebrow' => [
                    'label' => 'Надпись над заголовком',
                    'type' => 'text',
                    'max' => 120,
                    'group' => 'hero',
                    'default' => 'Московская Баскетбольная Ассоциация',
                ],
                'headline' => [
                    'label' => 'Заголовок',
                    'type' => 'text',
                    'max' => 60,
                    'group' => 'hero',
                    'default' => 'Баскетбол',
                ],
                'headline_accent' => [
                    'label' => 'Акцентная строка',
                    'type' => 'text',
                    'max' => 60,
                    'group' => 'hero',
                    'default' => 'рядом.',
                ],
                'lead' => [
                    'label' => 'Подзаголовок',
                    'type' => 'textarea',
                    'rows' => 2,
                    'max' => 240,
                    'group' => 'hero',
                    'default' => 'Игры, тренировки, команды, секции и площадки — в одном баскетбольном сообществе.',
                ],
                'venue_title' => [
                    'label' => 'Название площадки на листовке',
                    'type' => 'text',
                    'max' => 140,
                    'group' => 'hero',
                    'default_source' => 'venue.name',
                ],
                'venue_subtitle' => [
                    'label' => 'Подпись площадки',
                    'type' => 'text',
                    'max' => 180,
                    'group' => 'hero',
                    'default_source' => 'campaign.placement',
                ],
                'cta_line_1' => [
                    'label' => 'CTA — строка 1',
                    'type' => 'text',
                    'max' => 80,
                    'group' => 'cta',
                    'default' => 'Сканируй.',
                ],
                'cta_line_2' => [
                    'label' => 'CTA — строка 2',
                    'type' => 'text',
                    'max' => 80,
                    'group' => 'cta',
                    'default' => 'Выбери роль.',
                ],
                'cta_line_accent' => [
                    'label' => 'CTA — акцентная строка',
                    'type' => 'text',
                    'max' => 80,
                    'group' => 'cta',
                    'default' => 'Присоединяйся.',
                ],
                'step_1' => [
                    'label' => 'Шаг 01',
                    'type' => 'text',
                    'max' => 160,
                    'group' => 'cta',
                    'default' => 'Открой MSKBA по QR-коду.',
                ],
                'step_2' => [
                    'label' => 'Шаг 02',
                    'type' => 'text',
                    'max' => 180,
                    'group' => 'cta',
                    'default' => 'Выбери, зачем ты здесь: играть, тренировать, организовывать.',
                ],
                'step_3' => [
                    'label' => 'Шаг 03',
                    'type' => 'text',
                    'max' => 160,
                    'group' => 'cta',
                    'default' => 'Найди людей и баскетбол рядом с собой.',
                ],
                'qr_caption' => [
                    'label' => 'Подпись под QR',
                    'type' => 'text',
                    'max' => 80,
                    'group' => 'cta',
                    'default' => 'Открыть MSKBA',
                ],
            ],
        ],
    ],
];
