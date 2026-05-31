<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;
use App\Presentation\Navigation\Menus\MenuHelper;

final class MainMenu implements MenuHandler
{
    use MenuHelper;
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function items(): array
    {
        $user = request()->user();

        $items = [
            [
                'label' => 'Площадки',
                'url' => $this->routeUrl('venues'),
                'active' => $this->isActiveRoute('venues, venues.*'),
                'visible' => true,
            ],
            [
                'label' => 'Игры',
                'url' => $this->routeUrl('/#games'),
                'active' => $this->isActiveRoute('games, games.*'),
                'visible' => true,
            ],
            [
                'label' => 'Турниры',
                'url' => $this->routeUrl('/#tournaments'),
                'active' => $this->isActiveRoute('tournaments, tournaments.*'),
                'visible' => true,
            ],
            [
                'label' => 'Новости',
                'url' => $this->routeUrl('/#news'),
                'active' => $this->isActiveRoute('news, news.*'),
                'visible' => true,
            ],
            [
                'label' => 'Контакты',
                'url' => $this->routeUrl('/#contacts'),
                'active' => $this->isActiveRoute('contacts, contacts.*'),
                'visible' => true,
            ],
        ];

        if ($user) {
        }

        return $items;
    }
}
