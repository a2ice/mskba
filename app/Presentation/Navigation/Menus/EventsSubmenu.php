<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class EventsSubmenu implements MenuHandler
{
    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool, children?: array}>
     */
    public function items(): array
    {
        return [
            [
                'label' => 'Играть',
                'url' => '#/events/configurator?type=games',
                'active' => false,
                'visible' => true,
            ],
        ];
    }
}
