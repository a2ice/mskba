<?php

namespace App\Modules\Event\Infrastructure\Providers;

use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Event\Infrastructure\Http\Middleware\EnsureGameLifecycleState;
use App\Modules\Event\Infrastructure\Observers\GameRosterEntryObserver;
use App\Modules\Event\Presentation\Http\Controllers\GameLifecycleController;
use App\Modules\Event\Presentation\Http\Controllers\GameLineupController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class EventLifecycleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        GameRosterEntry::observe(GameRosterEntryObserver::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureGameLifecycleState::class);

        Route::middleware(['web', 'auth'])
            ->prefix('game-lifecycle')
            ->group(function (): void {
                Route::get('/{event}', [GameLifecycleController::class, 'show'])
                    ->name('events.game.lifecycle.show');
                Route::post('/{event}/start', [GameLifecycleController::class, 'start'])
                    ->name('events.game.lifecycle.start');
                Route::post('/{event}/end', [GameLifecycleController::class, 'end'])
                    ->name('events.game.lifecycle.end');
                Route::put('/{event}/lineup', GameLineupController::class)
                    ->name('events.game.lineup.update');
            });
    }
}
