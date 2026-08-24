<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserOperationalPermissionChecker
{
    public function allows(User $user, UserOperationalPermissionEnum $permission): bool
    {
        $user = $user->canonical();

        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }

        $snapshot = $user->operationalPermissions()
            ->where('permission', $permission->value)
            ->first();

        if ($snapshot !== null) {
            return (bool) $snapshot->is_allowed;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $permission->defaultAllowed();
    }
}
