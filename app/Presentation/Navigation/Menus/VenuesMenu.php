<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class VenuesMenu implements MenuHandler
{
    use MenuHelper;

    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function items(): array
    {
        return [
            [
                'label' => 'Все площадки',
                'url' => $this->routeUrl('venues'),
                'active' => $this->isActiveRoute('venues'),
                'visible' => true,
            ],
            [
                'label' => 'Добавить площадку',
                'url' => $this->routeUrl('venues.create'),
                'active' => $this->isActiveRoute('venues.create'),
                'visible' => true,
            ],
        ];
    }
}
