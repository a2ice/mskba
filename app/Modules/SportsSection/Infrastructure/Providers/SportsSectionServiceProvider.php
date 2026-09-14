<?php

namespace App\Modules\SportsSection\Infrastructure\Providers;

use App\Modules\SportsSection\Presentation\Http\Controllers\SportsSectionApplicationController;
use App\Modules\SportsSection\Presentation\Http\Controllers\SportsSectionTeamController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SportsSectionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth'])->group(function (): void {
            Route::post('/sections/{sportsSection}/applications', [SportsSectionApplicationController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('sports-sections.applications.store');
            Route::patch('/sections/{sportsSection}/applications/{joinRequest}/cancel', [SportsSectionApplicationController::class, 'cancel'])
                ->whereNumber('joinRequest')
                ->name('sports-sections.applications.cancel');

            Route::get('/account/sports-sections/{sportsSection}/applications', [SportsSectionApplicationController::class, 'manage'])
                ->name('account.sports-sections.applications');
            Route::patch('/account/sports-sections/{sportsSection}/applications/settings', [SportsSectionApplicationController::class, 'updateSettings'])
                ->name('account.sports-sections.applications.settings');
            Route::patch('/account/sports-sections/{sportsSection}/applications/{joinRequest}', [SportsSectionApplicationController::class, 'respond'])
                ->whereNumber('joinRequest')
                ->name('account.sports-sections.applications.respond');

            Route::get('/account/sports-sections/{sportsSection}/teams', [SportsSectionTeamController::class, 'index'])
                ->name('account.sports-sections.teams');
            Route::post('/account/sports-sections/{sportsSection}/teams', [SportsSectionTeamController::class, 'store'])
                ->name('account.sports-sections.teams.store');
            Route::delete('/account/sports-sections/{sportsSection}/teams/{team}', [SportsSectionTeamController::class, 'destroy'])
                ->whereNumber('team')
                ->name('account.sports-sections.teams.destroy');
        });
    }
}
