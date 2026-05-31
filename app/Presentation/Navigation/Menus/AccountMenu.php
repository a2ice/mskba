<?php

namespace App\Presentation\Navigation\Menus;

use App\Presentation\Navigation\MenuHandler;
use App\Presentation\Navigation\Menus\MenuHelper;

final class AccountMenu implements MenuHandler
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
                'label' => 'Профиль',
                'url' => $this->routeUrl('account'),
                'active' => $this->isActiveRoute('account'),
                'visible' => true,
            ],
        ];

        if ($user) {

            $items[] = [
                'label' => 'Настройки',
                'url' => $this->routeUrl('account.settings'),
                'active' => $this->isActiveRoute('account.settings'),
                'visible' => true,
            ];

            $items[] = [
                'label' => 'Контакты',
                'url' => $this->routeUrl('account.contacts'),
                'active' => $this->isActiveRoute('account.contacts'),
                'visible' => true,
            ];

            if($user->isConfirmed()) {
                $items[] = [
                    'label' => 'Контракты',
                    'url' => $this->routeUrl('account.contracts'),
                    'active' => $this->isActiveRoute('account.contracts'),
                    'visible' => true,
                ];

                if ($user->hasRole('venue_related')) {
                    $items[] = [
                        'label' => 'Мои площадки',
                        'url' => $this->routeUrl('account.venues'),
                        'active' => $this->isActiveRoute('account.venues, account.venues.*'),
                        'visible' => true,
                    ];
                }
            }

            $items[] = [
                'label' => 'Выйти',
                'url' => $this->routeUrl('auth.logout'),
                'active' => false,
                'visible' => true,
                'divider' => true,
            ];
        }

        return $items;
    }
}
