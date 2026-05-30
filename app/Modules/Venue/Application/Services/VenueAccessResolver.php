<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueAccessResolver
{
    public function __construct(
        private readonly VenueContractAccess $contracts,
    ) {}

    public function canView(?User $user, Venue $venue): bool
    {
        if ($venue->status->isPubliclyVisible()) {
            return true;
        }

        if ($this->isCreator($user, $venue)) {
            return true;
        }

        return $user !== null && $this->contracts->allows($user, $venue, VenuePermissionEnum::VIEW);
    }

    public function canEdit(?User $user, Venue $venue): bool
    {
        if ($this->isCreator($user, $venue)) {
            return true;
        }

        return $user !== null && $this->contracts->allows($user, $venue, VenuePermissionEnum::EDIT);
    }

    public function canEditSchedule(?User $user, Venue $venue): bool
    {
        return $user !== null && $this->contracts->allows($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE);
    }

    /**
     * @return array<int>
     */
    public function contractViewableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->allowedVenueIdsFor($user, VenuePermissionEnum::VIEW);
    }

    /**
     * @return array<int>
     */
    public function contractedVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->contractedVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function contractEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT);
    }

    /**
     * @return array<int>
     */
    public function contractScheduleEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->allowedVenueIdsFor($user, VenuePermissionEnum::EDIT_SCHEDULE);
    }

    private function isCreator(?User $user, Venue $venue): bool
    {
        return $user !== null && $venue->created_by_user_id === $user->id;
    }
}
