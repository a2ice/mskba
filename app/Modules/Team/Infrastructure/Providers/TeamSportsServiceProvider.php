<?php

namespace App\Modules\Team\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminTeamsController;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamMemberRemovalHierarchy;
use App\Modules\Team\Infrastructure\Http\Middleware\EnsureTeamUserContext;
use App\Modules\Team\Infrastructure\Observers\OwnerTeamMembershipObserver;
use App\Modules\Team\Presentation\Http\Controllers\TeamManagementController;
use App\Modules\Team\Presentation\Http\Controllers\TeamMemberSportsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TeamSportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ContractMembership::observe(OwnerTeamMembershipObserver::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureTeamUserContext::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureTeamMemberRemovalHierarchy::class);

        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::get('/teams/{team}/management', TeamManagementController::class)
                ->name('teams.management');
            Route::put('/teams/{team}/members/{membership}/sports', TeamMemberSportsController::class)
                ->whereNumber('membership')
                ->name('teams.members.sports.update');
        });

        Route::middleware(['web', 'auth', 'can:access-admin-panel'])
            ->get('/admin/teams/{team}', [AdminTeamsController::class, 'show'])
            ->name('admin.teams.show')
            ->defaults('breadcrumb', 'Команда');
    }
}
