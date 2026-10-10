<?php

namespace App\Presentation\Navigation\Menus;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\VenueBooking\Application\Queries\CountActionableVenueBookingRequests;
use App\Presentation\Navigation\MenuHandler;
use App\Presentation\Theming\ThemeResolver;

final class AccountMenu implements MenuHandler
{
    use MenuHelper;

    public function __construct(
        private readonly VenueAccessResolver $venueAccessResolver,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function items(): array
    {
        $user = request()->user();
        if ($user && app(ThemeResolver::class)->active() === 'mskba_app') {
            return $this->appItems($user);
        }

        // Preserve the existing menu contract for mskba_dark and other themes.
        $items = [[
            'label' => 'Профиль',
            'url' => $this->routeUrl('account'),
            'active' => $this->isActiveRoute('account'),
            'visible' => true,
        ]];

        if (! $user) {
            return $items;
        }

        $newNotificationsCount = app(CountNewUserNotificationsHandler::class)->handle($user);
        $items[] = $this->link('Кошелёк', 'account.wallet');
        $items[] = [
            'label' => 'Роли в проекте',
            'url' => $this->routeUrl('account.roles'),
            'active' => $this->isActiveRoute('account.roles, account.roles.*, account.participation-role'),
            'visible' => true,
        ];
        if ($this->hasVenueRelations($user)) {
            $items[] = $this->venueLink($user);
        }
        $items[] = $this->link('Мои команды', 'account.teams');
        if (config('features.sports_sections.enabled')) {
            $items[] = [
                'label' => 'Мои секции',
                'url' => $this->routeUrl('account.sports-sections.index'),
                'active' => $this->isActiveRoute('account.sports-sections.*'),
                'visible' => true,
            ];
        }
        $items[] = $this->link('Уведомления', 'account.notifications', $newNotificationsCount);
        $items[] = [
            'label' => 'Контакты',
            'url' => $this->routeUrl('account.contacts'),
            'active' => $this->isActiveRoute('account.contacts, account.contacts.*, account.telegram, account.telegram.*, account.vk, account.vk.*'),
            'visible' => true,
        ];
        $items[] = $this->link('Настройки', 'account.settings');
        if ($user->isConfirmed()) {
            $items[] = $this->link('Контракты', 'account.contracts');
        }
        $items[] = $this->link('Выйти', 'auth.logout', 0, true);

        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function appItems(User $user): array
    {
        $items = [
            $this->link('Обзор', 'account'),
            $this->link('Профиль', 'account.profile'),
            [
                'label' => 'Роли в проекте',
                'url' => $this->routeUrl('account.roles'),
                'active' => $this->isActiveRoute('account.roles, account.roles.*'),
                'visible' => true,
            ],
        ];

        // Navigation is an invitation to participate, not a report of existing relations.
        // Roles only control menu visibility; domain permissions are enforced by handlers.
        $roleSections = [
            UserParticipationRoleEnum::PLAYER->value => ['teams', 'games', 'trainings'],
            UserParticipationRoleEnum::COACH->value => ['sections', 'trainings', 'teams'],
            UserParticipationRoleEnum::VENUE_RELATED->value => ['venues', 'bookings', 'schedule'],
            UserParticipationRoleEnum::ORGANIZER->value => ['events', 'tournaments'],
            UserParticipationRoleEnum::REFEREE->value => ['games', 'assignments'],
            UserParticipationRoleEnum::STATISTICIAN->value => ['games', 'statistics'],
            UserParticipationRoleEnum::MEDIA->value => ['materials', 'events'],
        ];
        $definitions = [
            'teams' => ['Мои команды', 'account.teams', 'account.teams'],
            'games' => ['Мои игры', 'account.my-games', 'account.my-games'],
            'trainings' => ['Мои тренировки', 'account.my-trainings', 'account.my-trainings'],
            'sections' => ['Мои секции', 'account.sports-sections.index', 'account.sports-sections.*'],
            'venues' => ['Мои площадки', 'account.venues', 'account.venues, account.venues.*, account.venue-bookings.inbox'],
            'bookings' => ['Бронирования', 'account.my-bookings', 'account.my-bookings'],
            'schedule' => ['Расписание', 'account.venue-schedule', 'account.venue-schedule'],
            'events' => ['Мои мероприятия', 'account.my-events', 'account.my-events'],
            'tournaments' => ['Мои турниры', 'account.my-tournaments', 'account.my-tournaments'],
            'assignments' => ['Судейские назначения', 'account.referee-assignments', 'account.referee-assignments'],
            'statistics' => ['Статистика', 'account.statistics', 'account.statistics'],
            'materials' => ['Мои материалы', 'account.my-materials', 'account.my-materials'],
        ];
        $roles = $user->canonical()->participationRoles()->get(['role'])
            ->map(fn ($role): string => $role->role->value)->all();
        // Each active participation role owns one dedicated sidebar group.
        // Do not deduplicate sections across roles: the same destination can
        // intentionally appear under two different participation contexts.
        foreach ($roleSections as $roleValue => $sections) {
            if (! in_array($roleValue, $roles, true)) {
                continue;
            }

            $role = UserParticipationRoleEnum::from($roleValue);
            $children = [];
            foreach (array_unique($sections) as $key) {
                if ($key === 'sections' && ! config('features.sports_sections.enabled')) {
                    continue;
                }
                [$label, $route, $activePatterns] = $definitions[$key];
                $children[] = [
                    'label' => $label,
                    'url' => $this->routeUrl($route),
                    'active' => $this->isActiveRoute($activePatterns),
                    'visible' => true,
                ];
            }

            $isCurrentRole = request()->routeIs('account.participation-role')
                && request()->route('role') === $roleValue;
            $children[] = [
                'label' => 'Параметры',
                'url' => route('account.participation-role', ['role' => $roleValue]),
                'active' => $isCurrentRole,
                'visible' => true,
            ];
            $items[] = [
                'label' => $role->label(),
                'url' => null,
                'active' => collect($children)->contains(fn (array $item): bool => $item['active']),
                'visible' => true,
                'groupType' => 'participation-role',
                'role' => $roleValue,
                'children' => $children,
            ];
        }

        $items[] = $this->link('Уведомления', 'account.notifications', app(CountNewUserNotificationsHandler::class)->handle($user));
        $items[] = [
            'label' => 'Контакты',
            'url' => $this->routeUrl('account.contacts'),
            'active' => $this->isActiveRoute('account.contacts, account.contacts.*, account.telegram, account.telegram.*, account.vk, account.vk.*'),
            'visible' => true,
        ];
        $items[] = $this->link('Кошелёк', 'account.wallet');
        $items[] = $this->link('Настройки', 'account.settings');
        if ($user->isConfirmed()) {
            $items[] = $this->link('Контракты', 'account.contracts');
        }
        $items[] = $this->link('Выйти', 'auth.logout', 0, true);

        return $items;
    }

    /** @return array<string, mixed> */
    private function link(string $label, string $route, int $badge = 0, bool $divider = false): array
    {
        return [
            'label' => $label,
            'url' => $this->routeUrl($route),
            'active' => $this->isActiveRoute($route),
            'visible' => true,
            ...($badge > 0 ? ['badge' => $badge] : []),
            ...($divider ? ['divider' => true] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function venueLink(User $user): array
    {
        return [
            'label' => 'Мои площадки',
            'url' => $this->routeUrl('account.venues'),
            'active' => $this->isActiveRoute('account.venues, account.venues.*, account.venue-bookings.inbox'),
            'visible' => true,
            'badge' => app(CountActionableVenueBookingRequests::class)->totalFor($user),
            'badgeAttribute' => 'data-venue-booking-request-count',
        ];
    }

    private function hasVenueRelations(User $user): bool
    {
        return $this->venueAccessResolver->bootstrapOwnedVenueIdsFor($user) !== []
            || $this->venueAccessResolver->contractedVenueIdsFor($user) !== [];
    }
}
