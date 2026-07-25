<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;

final class UserOperationalPermissionChecker
{
    public function allows(User $user, UserOperationalPermissionEnum $permission): bool
    {
        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }

        $snapshot = $user->operationalPermissions()
            ->where('permission', $permission->value)
            ->first();

        return $snapshot?->is_allowed ?? true;
    }
}
