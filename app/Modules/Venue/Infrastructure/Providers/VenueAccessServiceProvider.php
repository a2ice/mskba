<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Moderation\Domain\Events\ModerationRequestApproved;
use App\Modules\Venue\Infrastructure\Listeners\ConfirmVenueAfterModerationRequestApproved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class VenueAccessServiceProvider extends ServiceProvider
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
        $canCreateVenue = fn ($user): bool => ! $user->isBlocked();

        Gate::define('add_venue', $canCreateVenue);
        Gate::define('venue-create-venue', $canCreateVenue);

        Event::listen(ModerationRequestApproved::class, ConfirmVenueAfterModerationRequestApproved::class);
    }
}
