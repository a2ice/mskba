<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueAccessResolver
{
    public function __construct(
        private readonly VenueMembershipAccess $memberships,
    ) {}

    public function canView(?User $user, Venue $venue): bool
    {
        if ($venue->status->isPubliclyVisible()) {
            return true;
        }

        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::VIEW)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue);
    }

    public function canEdit(?User $user, Venue $venue): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue);
    }

    public function canRemove(?User $user, Venue $venue): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::REMOVE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue);
    }

    public function canEditSchedule(?User $user, Venue $venue): bool
    {
        if ($user !== null && $this->memberships->allows($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE)) {
            return true;
        }

        return $this->isBootstrapCreator($user, $venue);
    }

    /**
     * @return array<int>
     */
    public function contractViewableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW);
    }

    /**
     * @return array<int>
     */
    public function contractedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->contractedVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function bootstrapOwnedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->bootstrapOwnedVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function contractEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT);
    }

    /**
     * @return array<int>
     */
    public function contractScheduleEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->memberships->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT_SCHEDULE);
    }

    private function isBootstrapCreator(?User $user, Venue $venue): bool
    {
        return $user !== null
            && $venue->created_by_user_id === $user->id
            && ! $this->memberships->hasActiveOwner($venue);
    }
}
