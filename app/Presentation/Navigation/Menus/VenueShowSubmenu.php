<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class VenueShowSubmenu implements MenuHandler
{
    public function items(): array
    {
        return [
            ['label' => 'Забронировать', 'url' => null, 'placeholder' => 'Бронирование площадки', 'visible' => true, 'active' => false],
            ['label' => 'Найти похожие', 'url' => null, 'placeholder' => 'Поиск похожих площадок', 'visible' => true, 'active' => false],
        ];
    }
}
