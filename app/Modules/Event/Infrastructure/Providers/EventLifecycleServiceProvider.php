<?php

namespace App\Modules\Event\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminEventsController;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Event\Infrastructure\Http\Middleware\EnsureGameLifecycleState;
use App\Modules\Event\Infrastructure\Observers\GameRosterEntryObserver;
use App\Modules\Event\Presentation\Http\Controllers\EventManagementController;
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

        Route::middleware(['web', 'auth', 'can:access-admin-panel'])
            ->get('/admin/events/{event}', [AdminEventsController::class, 'show'])
            ->name('admin.events.show')
            ->defaults('breadcrumb', 'Мероприятие');

        View::composer([
            'theme::pages.events.show',
            'theme::pages.events.game-show',
        ], function ($view): void {
            $data = $view->getData();
            $event = $data['event'] ?? null;
            $game = $data['game']
                ?? ($event?->type->value === 'game' ? $event->primaryGame : null);

            // Public pages keep personal contextual actions, but never expose
            // forms that mutate the event or other participants. The explicit
            // game management route preserves the permissions resolved by its
            // controller and renders the operational controls.
            if (! ($data['managementMode'] ?? false)) {
                $view->with('effectivePermissions', collect());
            }

            if (($data['canManage'] ?? false) && $event !== null) {
                $view->with(
                    'contextManagementUrl',
                    $game !== null
                        ? route('events.games.manage', [$event->routeIdentifier(), $game->id])
                        : route('events.management', $event->routeIdentifier()),
                );
                $view->with(
                    'contextManagementLabel',
                    $game !== null ? 'Управление игрой' : 'Управление мероприятием',
                );
            }
        });
    }
}
