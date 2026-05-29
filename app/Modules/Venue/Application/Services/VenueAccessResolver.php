<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\User;
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

        return $user !== null && $this->contracts->canView($user, $venue);
    }

    public function canEdit(?User $user, Venue $venue): bool
    {
        return $user !== null && $this->contracts->canEdit($user, $venue);
    }

    public function canEditSchedule(?User $user, Venue $venue): bool
    {
        return $user !== null && $this->contracts->canEditSchedule($user, $venue);
    }

    /**
     * @return array<int>
     */
    public function contractViewableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->viewableVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function contractEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->editableVenueIdsFor($user);
    }

    /**
     * @return array<int>
     */
    public function contractScheduleEditableVenueIdsFor(?User $user): array
    {
        return $user === null ? [] : $this->contracts->scheduleEditableVenueIdsFor($user);
    }
}
