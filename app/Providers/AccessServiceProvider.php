<?php

namespace App\Providers;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('access-admin-panel', fn (User $user): bool => $user->isAdmin());
        Gate::define(
            'coordination-create',
            fn (User $user): bool => app(UserOperationalPermissionChecker::class)
                ->allows($user, UserOperationalPermissionEnum::CREATE_COORDINATION),
        );
        Gate::define(
            'edit-venues-as-superadmin',
            fn (User $user): bool => $user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN),
        );
        Gate::define(
            'manage-users-as-superadmin',
            fn (User $user): bool => $user->isConfirmed() && $user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN),
        );
    }
}
