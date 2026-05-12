<?php

return [
    'active' => env('APP_THEME', 'mskba_dark'),

    'items' => [
        'mskba_dark' => [
            'name' => 'MSKBA Dark',
            'views' => resource_path('themes/mskba_dark/views'),
            'assets' => [
                'resources/themes/mskba_dark/css/app.css',
                'resources/themes/mskba_dark/js/app.js',
            ],
        ],
    ],
];
