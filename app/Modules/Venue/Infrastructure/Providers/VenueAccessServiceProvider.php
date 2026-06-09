<?php

namespace App\Modules\Venue\Infrastructure\Providers;

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
        $canCreateVenue = fn ($user): bool => $user->hasRole('venue_related') && $user->isConfirmed();

        Gate::define('add_venue', $canCreateVenue);
        Gate::define('venue-create-venue', $canCreateVenue);
    }
}
