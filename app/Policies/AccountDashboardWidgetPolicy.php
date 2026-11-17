<?php

namespace App\Policies;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class AccountDashboardWidgetPolicy
{
    private function permitted(User $user, Permission $permission): bool
    {
        $manager = app(BackofficePermissionManager::class);

        return $manager->allows($user, Permission::BACKOFFICE_ACCESS)
            && $manager->allows($user, Permission::BACKOFFICE_WIDGET_VIEW)
            && $manager->allows($user, $permission);
    }

    private function canEditType(User $user, AccountDashboardWidget $widget): bool
    {
        return $widget->kind !== 'custom_html'
            || ($user->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN
                && $user->canonical()->isConfirmed());
    }

    public function viewAny(User $user): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_WIDGET_VIEW);
    }

    public function view(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->viewAny($user) && $this->canEditType($user, $widget);
    }

    public function create(User $user): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_WIDGET_CREATE);
    }

    public function update(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_WIDGET_EDIT)
            && $this->canEditType($user, $widget);
    }

    public function delete(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_WIDGET_DELETE)
            && $this->canEditType($user, $widget);
    }

    public function deleteAny(User $user): bool
    {
        return $this->permitted($user, Permission::BACKOFFICE_WIDGET_DELETE);
    }
}
