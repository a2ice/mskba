<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Venue\Infrastructure\Listeners\ConfirmVenueAfterModerationRequestApproved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class VenueAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $canCreateVenue = fn ($user): bool => ! $user->isBlocked();

        Gate::define('add_venue', $canCreateVenue);
        Gate::define('venue-create-venue', $canCreateVenue);

        Event::listen(ModerationRequestApproved::class, ConfirmVenueAfterModerationRequestApproved::class);

        View::composer('theme::pages.venues.show', function ($view): void {
            $venue = $view->getData()['venue'] ?? null;

            if ($venue !== null && ($venue->canEdit || $venue->canEditSchedule)) {
                $view->with('contextManagementUrl', route('account.venues.show', $venue->routeIdentifier()));
                $view->with('contextManagementLabel', 'Управление площадкой');
            }
        });
    }
}
