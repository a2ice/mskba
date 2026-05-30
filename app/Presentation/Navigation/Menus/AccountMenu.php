<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;

final class AccountMenu implements MenuHandler
{
    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool}>
     */
    public function items(): array
    {
        $user = request()->user();

        $items = [
            [
                'label' => 'Профиль',
                'url' => route('account'),
                'active' => request()->routeIs('account'),
                'visible' => true,
            ],
            [
                'label' => 'Контакты',
                'url' => route('account'),
                'active' => false,
                'visible' => true,
            ],
            [
                'label' => 'Контракты',
                'url' => route('account.contracts'),
                'active' => request()->routeIs('account.contracts'),
                'visible' => true,
            ],
            [
                'label' => 'Площадки',
                'url' => route('account.venues'),
                'active' => request()->routeIs('account.venues', 'account.venues.show', 'account.venues.edit'),
                'visible' => true,
            ],
        ];

        if ($user) {
            $items[] = [
                'label' => 'Выйти',
                'url' => route('auth.logout'),
                'active' => false,
                'visible' => true,
                'divider' => true,
            ];
        }

        return $items;
    }
}
