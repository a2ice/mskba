<?php

namespace App\Modules\Team\Infrastructure\Providers;

use App\Modules\Team\Presentation\Http\Controllers\TeamMemberSportsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class TeamSportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth'])
            ->put('/teams/{team}/members/{membership}/sports', TeamMemberSportsController::class)
            ->whereNumber('membership')
            ->name('teams.members.sports.update');
    }
}
