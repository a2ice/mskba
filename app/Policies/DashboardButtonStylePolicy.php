<?php

namespace App\Policies;

use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Models\User;

final class DashboardButtonStylePolicy
{
    private function permitted(User $user, Permission $permission): bool
    {
        $permissions = app(BackofficePermissionManager::class);

        return $permissions->allows($user, Permission::BACKOFFICE_ACCESS)
            && $permissions->allows($user, Permission::BACKOFFICE_STYLE_VIEW)
            && $permissions->allows($user, $permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_STYLE_VIEW);
    }

    public function view(User $user, DashboardButtonStyle $style): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_STYLE_MANAGE);
    }

    public function update(User $user, DashboardButtonStyle $style): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, DashboardButtonStyle $style): bool
    {
        return $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }
}
