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
        ],
    ],
];
