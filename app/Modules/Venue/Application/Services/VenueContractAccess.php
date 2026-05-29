<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Application\Contracts\ContractAccessInterface;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueContractAccess
{
    private const SUBJECT_TYPE = 'venue';

    public function __construct(
        private readonly ContractAccessInterface $contracts,
    ) {}

    public function canView(User $user, Venue $venue): bool
    {
        return $this->contracts->allows(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            subjectId: $venue->id,
            permission: VenuePermissionEnum::VIEW->value,
        );
    }

    public function canEdit(User $user, Venue $venue): bool
    {
        return $this->contracts->allows(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            subjectId: $venue->id,
            permission: VenuePermissionEnum::EDIT->value,
        );
    }

    public function canEditSchedule(User $user, Venue $venue): bool
    {
        return $this->contracts->allows(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            subjectId: $venue->id,
            permission: VenuePermissionEnum::EDIT_SCHEDULE->value,
        );
    }

    /**
     * @return array<int>
     */
    public function viewableVenueIdsFor(User $user): array
    {
        return $this->contracts->allowedSubjectIds(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            permission: VenuePermissionEnum::VIEW->value,
        );
    }

    /**
     * @return array<int>
     */
    public function editableVenueIdsFor(User $user): array
    {
        return $this->contracts->allowedSubjectIds(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            permission: VenuePermissionEnum::EDIT->value,
        );
    }

    /**
     * @return array<int>
     */
    public function scheduleEditableVenueIdsFor(User $user): array
    {
        return $this->contracts->allowedSubjectIds(
            user: $user,
            subjectType: self::SUBJECT_TYPE,
            permission: VenuePermissionEnum::EDIT_SCHEDULE->value,
        );
    }
}
