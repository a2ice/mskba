<?php

use App\Presentation\Navigation\Menus\MainMenu;
use App\Presentation\Navigation\Menus\AccountMenu;
use App\Presentation\Navigation\Menus\VenuesMenu;

return [
    'main' => MainMenu::class,
    'account' => AccountMenu::class,
    'venues' => VenuesMenu::class,
];
