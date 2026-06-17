<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;
use App\Presentation\Navigation\Menus\MenuHelper;

final class MainMenu implements MenuHandler
{
    use MenuHelper;
    /**
     * @return array<int, array{label: string, url: string|null, active: bool, visible: bool, children?: array<int, array{label: string, url: string|null, active: bool, visible: bool}>}>
     */
    public function items(): array
    {
        $user = request()->user();

        $moreItems = [
            [
                'label' => 'FAQ',
                'url' => $this->routeUrl('faq.index'),
                'active' => $this->isActiveRoute('faq.*'),
                'visible' => true,
            ],
            [
                'label' => 'Новости',
                'url' => $this->routeUrl('/#news'),
                'active' => $this->isActiveRoute('news, news.*'),
                'visible' => true,
            ],
            [
                'label' => 'О проекте',
                'url' => $this->routeUrl('/#about'),
                'active' => $this->isActiveRoute('about, about.*'),
                'visible' => true,
            ],
            [
                'label' => 'Партнерам',
                'url' => $this->routeUrl('/#partners'),
                'active' => $this->isActiveRoute('partners, partners.*'),
                'visible' => true,
            ],
        ];

        if($user?->isAdmin()) {
            $moreItems[] = [
                'divider' => true,
                'label' => 'Админка',
                'url' => $this->routeUrl('admin.dashboard'),
                'active' => $this->isActiveRoute('admin.*'),
                'visible' => true,
            ];

        }

        $moreGames = [
            [
                'label' => 'Игры',
                'url' => $this->routeUrl('/#games'),
                'active' => $this->isActiveRoute('games, games.*'),
                'visible' => true,
            ],
            [
                'label' => 'Тренировки',
                'url' => $this->routeUrl('/#training'),
                'active' => $this->isActiveRoute('training, training.*'),
                'visible' => true,
            ],
            [
                'label' => 'Турниры',
                'url' => $this->routeUrl('/#tournaments'),
                'active' => $this->isActiveRoute('tournaments, tournaments.*'),
                'visible' => true,
            ],
        ];

        $items = [
            [
                'label' => 'Площадки',
                'url' => $this->routeUrl('venues'),
                'active' => $this->isActiveRoute('venues, venues.*'),
                'visible' => true,
            ],
            [
                'label' => 'Мероприятия',
                'url' => $this->routeUrl('/#games'),
                'active' => $this->isActiveRoute('games, games.*'),
                'visible' => true,
                'children' => $moreGames,
            ],
            [
                'label' => 'Команды',
                'url' => $this->routeUrl('/#teams'),
                'active' => $this->isActiveRoute('teams, teams.*'),
                'visible' => true,
            ],
            [
                'label' => 'Новости',
                'url' => $this->routeUrl('/#teams'),
                'active' => $this->isActiveRoute('teams, teams.*'),
                'visible' => true,
            ],
            [
                'label' => 'Магазин',
                'url' => $this->routeUrl('/#shop'),
                'active' => $this->isActiveRoute('shop, shop.*'),
                'visible' => true,
            ],
            [
                'label' => 'О нас',
                'url' => $this->routeUrl('/#about'),
                'active' => $this->isActiveRoute('about, about.*'),
                'visible' => true,
            ],
            [
                'label' => 'Еще',
                'url' => null,
                'active' => $this->hasActiveItem($moreItems),
                'visible' => false,
                'children' => $moreItems,
            ],
        ];

        if ($user) {
        }

        return $items;
    }

    /**
     * @param array<int, array{label: string, url: string|null, active: bool, visible: bool}> $items
     */
    private function hasActiveItem(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['visible'] && $item['active']) {
                return true;
            }
        }

        return false;
    }
}
