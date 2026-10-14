<?php

namespace App\Policies;

use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;

final class DashboardButtonStylePolicy
{
    private function authorized(User $user): bool
    {
        $actor = $user->canonical();

        return $actor->isConfirmed() && $actor->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    public function viewAny(User $user): bool { return $this->authorized($user); }
    public function view(User $user, DashboardButtonStyle $style): bool { return $this->authorized($user); }
    public function create(User $user): bool { return $this->authorized($user); }
    public function update(User $user, DashboardButtonStyle $style): bool { return $this->authorized($user); }
    public function delete(User $user, DashboardButtonStyle $style): bool { return $this->authorized($user); }
    public function deleteAny(User $user): bool { return $this->authorized($user); }
}
