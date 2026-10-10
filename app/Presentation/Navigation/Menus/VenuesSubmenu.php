<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class VenuesSubmenu implements MenuHandler
{
    public function items(): array
    {
        return [
            ['label' => 'Создать', 'url' => null, 'placeholder' => 'Создание площадки', 'visible' => true, 'active' => false],
            ['label' => 'Найти', 'url' => null, 'placeholder' => 'Поиск площадки', 'visible' => true, 'active' => false],
        ];
    }
}
