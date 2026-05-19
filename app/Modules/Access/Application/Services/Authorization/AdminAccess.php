<?php

namespace App\Modules\Access\Application\Services\Authorization;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

class AdminAccess
{
    public function canViewAdminPanel(User $user): bool
    {
        return $this->hasConfirmedRoleAtLeast($user, UserSystemRoleEnum::EDITOR);
    }

    public function canManageUsers(User $user): bool
    {
        return $this->hasConfirmedRoleAtLeast($user, UserSystemRoleEnum::ADMIN);
    }

    public function canManageTournaments(User $user): bool
    {
        return $this->hasConfirmedRoleAtLeast($user, UserSystemRoleEnum::MODERATOR);
    }

    public function canManageSettings(User $user): bool
    {
        return $this->hasConfirmedRoleAtLeast($user, UserSystemRoleEnum::SUPERADMIN);
    }

    public function canManageContent(User $user): bool
    {
        return $this->hasConfirmedRoleAtLeast($user, UserSystemRoleEnum::EDITOR);
    }

    private function hasConfirmedRoleAtLeast(?User $user, UserSystemRoleEnum $role): bool
    {
        return $user?->status === UserStatusEnum::CONFIRMED
            && ($user->system_role?->atLeast($role) ?? false);
    }
}