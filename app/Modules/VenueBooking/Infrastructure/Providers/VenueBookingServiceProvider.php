<?php

namespace App\Modules\VenueBooking\Infrastructure\Providers;

use App\Modules\VenueBooking\Application\Services\VenueBookingCommandContext;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentSet;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentWithdrawn;
use App\Modules\VenueBooking\Domain\Events\VenueBookingMessageSent;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPolicyPublished;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Listeners\InvalidateVenueSearchAfterPolicyPublished;
use App\Modules\VenueBooking\Infrastructure\Listeners\NotifyVenueBookingMessageRecipients;
use App\Modules\VenueBooking\Infrastructure\Listeners\QueueContributionSummaryNotification;
use App\Modules\VenueBooking\Infrastructure\Observers\VenueBookingProjectionObserver;
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
        VenueBooking::observe(VenueBookingProjectionObserver::class);
        Event::listen(VenueBookingPolicyPublished::class, InvalidateVenueSearchAfterPolicyPublished::class);
        Event::listen(VenueBookingMessageSent::class, NotifyVenueBookingMessageRecipients::class);
        Event::listen(ContributionCommitmentSet::class, QueueContributionSummaryNotification::class);
        Event::listen(ContributionCommitmentWithdrawn::class, QueueContributionSummaryNotification::class);
    }
}
