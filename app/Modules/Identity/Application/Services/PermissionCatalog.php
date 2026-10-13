<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;

/** One discoverable catalog, with the existing domain enums as canonical definitions. */
final class PermissionCatalog
{
    /** @return array<string, array{label:string, scope:string, permissions:array, min_role?:mixed}> */
    public function groups(): array
    {
        $global = collect(UserOperationalPermissionEnum::cases());

        return [
            'creation' => ['label' => 'Создание', 'scope' => 'user', 'min_role' => UserSystemRoleEnum::USER, 'permissions' => $global->filter(fn ($p) => str_ends_with($p->value, '.create'))->values()->all()],
            'security' => ['label' => 'Администрирование пользователей', 'scope' => 'user', 'min_role' => UserSystemRoleEnum::ADMIN, 'permissions' => $global->reject(fn ($p) => str_ends_with($p->value, '.create'))->values()->all()],
            'team' => ['label' => 'Команды', 'scope' => 'team', 'permissions' => TeamPermissionEnum::cases()],
            'venue' => ['label' => 'Площадки', 'scope' => 'venue', 'permissions' => VenuePermissionEnum::cases()],
            'event' => ['label' => 'Мероприятия и мини-игры', 'scope' => 'event', 'permissions' => EventResponsibilityPermissionEnum::cases()],
            'tournament' => ['label' => 'Турниры', 'scope' => 'tournament', 'permissions' => TournamentPermissionEnum::cases()],
            'section' => ['label' => 'Спортивные секции', 'scope' => 'section', 'permissions' => SportsSectionPermissionEnum::cases()],
        ];
    }

    /** @return array<string, array{label:string, scope:string, permissions:array, min_role?:mixed}> */
    public function userGroups(): array
    {
        return array_filter($this->groups(), fn ($group) => $group['scope'] === 'user');
    }
}
