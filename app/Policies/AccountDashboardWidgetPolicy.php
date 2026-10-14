<?php
namespace App\Policies;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class AccountDashboardWidgetPolicy
{
    private function isAdmin(User $user): bool
    {
        return $user->canonical()->isAdmin();
    }

    private function isSuperadmin(User $user): bool
    {
        $user = $user->canonical();

        return $user->isConfirmed() && $user->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->isAdmin($user)
            && ($widget->kind !== 'custom_html' || $this->isSuperadmin($user));
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->view($user, $widget);
    }

    public function delete(User $user, AccountDashboardWidget $widget): bool
    {
        return $this->isSuperadmin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isSuperadmin($user);
    }
}
