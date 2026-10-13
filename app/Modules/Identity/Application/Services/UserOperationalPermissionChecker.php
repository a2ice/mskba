<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserOperationalPermissionChecker
{
    public function allows(User $user, UserOperationalPermissionEnum $permission): bool
    {
        $user = $user->canonical();

        // Sensitive review cannot be delegated to a lower system role.
        if ($permission === UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES
            && (! $user->isConfirmed() || ! $user->system_role->atLeast(UserSystemRoleEnum::ADMIN))) {
            return false;
        }

        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }

        $snapshot = $user->operationalPermissions()
            ->where('permission', $permission->value)
            ->first();

        if ($snapshot !== null) {
            return (bool) $snapshot->is_allowed;
        }

        return $permission->defaultAllowedFor($user->system_role);
    }
}
