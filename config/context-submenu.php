<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Context submenu
    |--------------------------------------------------------------------------
    |
    | A section can match one exact path and/or a regular expression. Exact
    | matches win over regex matches. Items may contain nested "children".
    |
    */
    'sections' => [
        [
            'exact' => [],
            'pattern' => '#^/events(?:/|$)#',
            'items' => [
                [
                    'label' => 'Играть',
                    'url' => '#/events/configurator?type=games',
                ],
            ],
        ],
    ],
];
