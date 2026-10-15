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

        if (in_array($permission, [
            UserOperationalPermissionEnum::OPERATOR_PRESENCE_VIEW,
            UserOperationalPermissionEnum::OPERATOR_SESSION_HISTORY_VIEW,
            UserOperationalPermissionEnum::OPERATOR_CONVERSATION_HANDLE,
            UserOperationalPermissionEnum::OPERATOR_MARKETING_SEND,
        ], true) && ! $user->isConfirmed()) {
            return false;
        }

        if ($permission === UserOperationalPermissionEnum::HANDLE_SUPPORT_TICKETS && ! $user->isConfirmed()) {
            return false;
        }

        if ($user->isBlocked() || $user->trashed()) {
            return false;
        }

        if ($permission->isBackoffice()) {
            if (! $user->isConfirmed() || $user->system_role === UserSystemRoleEnum::SYSTEM) {
                return false;
            }
            // Recovery invariant: a superadmin cannot revoke their own
            // ability to administer the backoffice.
            if ($user->system_role === UserSystemRoleEnum::SUPERADMIN) {
                return true;
            }
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
