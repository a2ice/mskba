<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;

final readonly class VenueCommercialAccess
{
    public function __construct(private VenueMembershipAccess $memberships) {}

    public function allowsForCourt(User $user, VenueCourt $court, VenuePermissionEnum $permission): bool
    {
        $user = $user->canonical();
        if ($user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            return true;
        }

        return $this->memberships->allowsForCourt($user, $court, $permission);
    }

    public function allows(User $user, Venue $venue, VenuePermissionEnum $permission): bool
    {
        $user = $user->canonical();

        if ($user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            return true;
        }

        return $this->memberships->allows($user, $venue, $permission);
    }
}
