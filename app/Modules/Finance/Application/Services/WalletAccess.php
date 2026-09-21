<?php

namespace App\Modules\Finance\Application\Services;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Finance\Domain\Enums\WalletOwnerTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final readonly class WalletAccess
{
    public function __construct(
        private TeamManagementAccess $teams,
        private EventManagementAccess $events,
        private VenueCommercialAccess $venues,
    ) {}

    public function allows(Actor $actor, WalletOwnerTypeEnum $ownerType, int $ownerId): bool
    {
        $user = $actor->user?->canonical();
        if (! $user instanceof User || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        return match ($ownerType) {
            WalletOwnerTypeEnum::USER => $this->allowsUser($user, $ownerId),
            WalletOwnerTypeEnum::TEAM => $this->allowsTeam($actor, $ownerId),
            WalletOwnerTypeEnum::EVENT => $this->allowsEvent($actor, $ownerId),
            WalletOwnerTypeEnum::VENUE => $this->allowsVenue($user, $ownerId),
        };
    }

    private function allowsUser(User $user, int $ownerId): bool
    {
        $owner = User::query()->find($ownerId);

        return $owner !== null && $user->isSameIdentity($owner);
    }

    private function allowsTeam(Actor $actor, int $ownerId): bool
    {
        $team = Team::query()->find($ownerId);

        return $team !== null
            && $this->teams->allows($team, $actor, TeamPermissionEnum::MANAGE_FUNDS);
    }

    private function allowsEvent(Actor $actor, int $ownerId): bool
    {
        $event = Event::query()->find($ownerId);

        return $event !== null
            && $this->events->allows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_FUNDS);
    }

    private function allowsVenue(User $user, int $ownerId): bool
    {
        $venue = Venue::query()->find($ownerId);

        return $venue !== null
            && $this->venues->allows($user, $venue, VenuePermissionEnum::MANAGE_FUNDS);
    }
}
