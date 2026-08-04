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
            'manage-content',
            fn (User $user): bool => $user->isConfirmed()
                && $user->system_role->atLeast(UserSystemRoleEnum::EDITOR),
        );
        Gate::define(
            'manage-user-operational-permissions',
            fn (User $actor, User $target): bool => $actor->isConfirmed()
                && $actor->system_role->atLeast(UserSystemRoleEnum::ADMIN)
                && ! $actor->is($target)
                && $actor->system_role->numericValue() > $target->system_role->numericValue(),
        );
        Gate::define(
            'coordination-create',
            fn (User $user): bool => app(UserOperationalPermissionChecker::class)
                ->allows($user, UserOperationalPermissionEnum::CREATE_COORDINATION),
        );
        Gate::define(
            'team-create',
            fn (User $user): bool => $user->isConfirmed()
                && app(UserOperationalPermissionChecker::class)
                    ->allows($user, UserOperationalPermissionEnum::CREATE_TEAM),
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
