<?php

namespace App\Presentation\Navigation\Menus;

use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Presentation\Navigation\MenuHandler;

final class AccountMenu implements MenuHandler
{
    use MenuHelper;

    public function __construct(
        private readonly VenueAccessResolver $venueAccessResolver,
    ) {}

    /**
     * @return array<int, array{label: string, url: string, active: bool, visible: bool, divider?: bool, badge?: int}>
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
            $newNotificationsCount = app(CountNewUserNotificationsHandler::class)->handle($user);

            $items[] = [
                'label' => 'Роли в проекте',
                'url' => $this->routeUrl('account.roles'),
                'active' => $this->isActiveRoute('account.roles, account.roles.*, account.participation-role'),
                'visible' => true,
            ];

            if (
                $this->venueAccessResolver->bootstrapOwnedVenueIdsFor($user) !== []
                || $this->venueAccessResolver->contractedVenueIdsFor($user) !== []
            ) {
                $items[] = [
                    'label' => 'Мои площадки',
                    'url' => $this->routeUrl('account.venues'),
                    'active' => $this->isActiveRoute('account.venues, account.venues.*'),
                    'visible' => true,
                ];
            }

            $items[] = [
                'label' => 'Мои команды',
                'url' => $this->routeUrl('account.teams'),
                'active' => $this->isActiveRoute('account.teams'),
                'visible' => true,
            ];

            $items[] = [
                'label' => 'Уведомления',
                'url' => $this->routeUrl('account.notifications'),
                'active' => $this->isActiveRoute('account.notifications'),
                'visible' => true,
                'badge' => $newNotificationsCount,
            ];

            $items[] = [
                'label' => 'Контакты',
                'url' => $this->routeUrl('account.contacts'),
                'active' => $this->isActiveRoute('account.contacts'),
                'visible' => true,
            ];

            $items[] = [
                'label' => 'Telegram',
                'url' => $this->routeUrl('account.telegram'),
                'active' => $this->isActiveRoute('account.telegram, account.telegram.*'),
                'visible' => true,
            ];

            $items[] = [
                'label' => 'Настройки',
                'url' => $this->routeUrl('account.settings'),
                'active' => $this->isActiveRoute('account.settings'),
                'visible' => true,
            ];

            if ($user->isConfirmed()) {
                $items[] = [
                    'label' => 'Контракты',
                    'url' => $this->routeUrl('account.contracts'),
                    'active' => $this->isActiveRoute('account.contracts'),
                    'visible' => true,
                ];

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
