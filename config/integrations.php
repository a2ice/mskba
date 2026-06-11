<?php

return [
    'address' => [
        'provider' => env('ADDRESS_SUGGEST_PROVIDER', 'yandex'),
        'default_country' => env('ADDRESS_DEFAULT_COUNTRY', 'Россия'),
        'default_city' => env('ADDRESS_DEFAULT_CITY', 'Москва'),
        'suggest_limit' => (int) env('ADDRESS_SUGGEST_LIMIT', 5),
    ],

    'yandex' => [
        'api_key' => env('YANDEX_MAPS_API_KEY'),
    ],
];
