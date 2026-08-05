<?php

namespace App\Modules\Team\Infrastructure\Providers;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Infrastructure\Observers\OwnerTeamMembershipObserver;
use App\Modules\Team\Presentation\Http\Controllers\TeamMemberSportsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TeamSportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ContractMembership::observe(OwnerTeamMembershipObserver::class);

        Route::middleware(['web', 'auth'])
            ->put('/teams/{team}/members/{membership}/sports', TeamMemberSportsController::class)
            ->whereNumber('membership')
            ->name('teams.members.sports.update');
    }
}
