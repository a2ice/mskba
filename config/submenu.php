<?php

use App\Presentation\Navigation\Menus\EventsSubmenu;

return [
    /*
    |--------------------------------------------------------------------------
    | Context submenus
    |--------------------------------------------------------------------------
    |
    | Exact URL matches have priority over section patterns. Each matching
    | section delegates its items to a MenuHandler, just like config/menus.php.
    |
    */
    'sections' => [
        [
            'exact' => [],
            'pattern' => '#^/events(?:/|$)#',
            'handler' => EventsSubmenu::class,
        ],
    ],
];
