<?php

namespace App\Modules\VenueBooking\Infrastructure\Providers;

use App\Modules\VenueBooking\Application\Services\VenueBookingCommandContext;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPolicyPublished;
use App\Modules\VenueBooking\Infrastructure\Listeners\InvalidateVenueSearchAfterPolicyPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class VenueBookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(VenueBookingCommandContext::class);
    }

    public function boot(): void
    {
        Event::listen(VenueBookingPolicyPublished::class, InvalidateVenueSearchAfterPolicyPublished::class);
    }
}
