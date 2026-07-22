<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

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

        if ($user?->isAdmin()) {
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
                'url' => route('events.index', ['type' => 'game']),
                'active' => $this->isActiveRoute('events.*') && request('type') === 'game',
                'visible' => true,
            ],
            [
                'label' => 'Тренировки',
                'url' => route('events.index', ['type' => 'training']),
                'active' => $this->isActiveRoute('events.*') && request('type') === 'training',
                'visible' => true,
            ],
            [
                'label' => 'Игровые тренировки',
                'url' => route('events.index', ['type' => 'game_training']),
                'active' => $this->isActiveRoute('events.*') && request('type') === 'game_training',
                'visible' => true,
            ],
            [
                'label' => 'Турниры',
                'url' => route('events.index', ['type' => 'tournament']),
                'active' => $this->isActiveRoute('events.*') && request('type') === 'tournament',
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
                'url' => $this->routeUrl('events.index'),
                'active' => $this->isActiveRoute('events.*'),
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
                'url' => $this->routeUrl('/#news'),
                'active' => $this->isActiveRoute('news, news.*'),
                'visible' => true,
            ],
            [
                'label' => 'Магазин',
                'url' => $this->routeUrl('/#shop'),
                'active' => $this->isActiveRoute('shop, shop.*'),
                'visible' => true,
            ],
            [
                'label' => 'Еще',
                'url' => null,
                'active' => $this->hasActiveItem($moreItems),
                'visible' => true,
                'children' => $moreItems,
            ],
        ];

        return $items;
    }

    /**
     * @param  array<int, array{label: string, url: string|null, active: bool, visible: bool}>  $items
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
