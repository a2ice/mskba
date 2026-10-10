<?php

use App\Presentation\Navigation\Menus\EventsSubmenu;
use App\Presentation\Navigation\Menus\VenuesSubmenu;
use App\Presentation\Navigation\Menus\VenueShowSubmenu;

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
        'venues.show' => [
            'pattern' => '#^/venues/[^/]+$#',
            'routes' => ['venues.show'],
            'parent' => 'venues',
            'include_parent' => false, // Override by default; true appends parent options.
            'handler' => VenueShowSubmenu::class,
        ],
        'venues' => [
            'pattern' => '#^/venues(?:/|$)#',
            'handler' => VenuesSubmenu::class,
        ],
        [
            'exact' => [],
            'pattern' => '#^/events(?:/|$)#',
            'handler' => EventsSubmenu::class,
        ],
    ],
];
