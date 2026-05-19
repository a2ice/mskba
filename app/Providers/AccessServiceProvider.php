<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Access\Application\Services\Authorization\AdminAccess;

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
        $adminAccess = app(AdminAccess::class);

        Gate::define('access-admin-panel', fn($user) => $adminAccess->canViewAdminPanel($user));
        Gate::define('manage-users', fn($user) => $adminAccess->canManageUsers($user));
        Gate::define('manage-tournaments', fn($user) => $adminAccess->canManageTournaments($user));
        Gate::define('manage-settings', fn($user) => $adminAccess->canManageSettings($user));
        Gate::define('manage-content', fn($user) => $adminAccess->canManageContent($user));
    }
}
