<?php

namespace App\Modules\Event\Infrastructure\Providers;

use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Event\Infrastructure\Http\Middleware\EnsureGameLifecycleState;
use App\Modules\Event\Infrastructure\Observers\GameRosterEntryObserver;
use App\Modules\Event\Presentation\Http\Controllers\EventManagementController;
use App\Modules\Event\Presentation\Http\Controllers\GameLifecycleController;
use App\Modules\Event\Presentation\Http\Controllers\GameLineupController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class EventLifecycleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        GameRosterEntry::observe(GameRosterEntryObserver::class);
        $this->app['router']->pushMiddlewareToGroup('web', EnsureGameLifecycleState::class);

        Route::middleware(['web', 'auth'])
            ->get('/events/{event}/management', EventManagementController::class)
            ->name('events.management')
            ->defaults('breadcrumb', 'Управление мероприятием');

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

        View::composer([
            'theme::pages.events.show',
            'theme::pages.events.game-show',
        ], function ($view): void {
            $data = $view->getData();
            $event = $data['event'] ?? null;

            // Public pages keep personal contextual actions, but never expose
            // forms that mutate the event, game state or other participants.
            $view->with('effectivePermissions', collect());

            if (! ($data['canManage'] ?? false) || $event === null) {
                return;
            }

            $isGameView = $view->getName() === 'theme::pages.events.game-show';
            $view->with(
                'contextManagementUrl',
                $isGameView
                    ? route('events.game.manage', $event->routeIdentifier())
                    : route('events.management', $event->routeIdentifier()),
            );
            $view->with(
                'contextManagementLabel',
                $isGameView ? 'Управление игрой' : 'Управление мероприятием',
            );
        });
    }
}
