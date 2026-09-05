<?php

namespace App\Modules\VenueBooking\Infrastructure\Providers;

use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Services\VenueBookingCommandContext;
use App\Modules\VenueBooking\Domain\Events\BookingProjectionUpdated;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentSet;
use App\Modules\VenueBooking\Domain\Events\ContributionCommitmentWithdrawn;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingMessageSent;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPolicyPublished;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Listeners\BroadcastVenueBookingProjectionUpdate;
use App\Modules\VenueBooking\Infrastructure\Listeners\CreateEventFromConfirmedBookingIntent;
use App\Modules\VenueBooking\Infrastructure\Listeners\InvalidateVenueSearchAfterPolicyPublished;
use App\Modules\VenueBooking\Infrastructure\Listeners\NotifyVenueBookingLifecycleRecipients;
use App\Modules\VenueBooking\Infrastructure\Listeners\NotifyVenueBookingMessageRecipients;
use App\Modules\VenueBooking\Infrastructure\Listeners\QueueContributionSummaryNotification;
use App\Modules\VenueBooking\Infrastructure\Observers\VenueBookingProjectionObserver;
use App\Modules\VenueBooking\Infrastructure\Payments\ExternalManualPaymentAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class VenueBookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(VenueBookingCommandContext::class);
        $this->app->singleton(PaymentProviderPort::class, function (): PaymentProviderPort {
            if (config('services.venue_rental_payment.driver') !== 'external_manual') {
                throw new \LogicException('Configured venue rental payment driver is not installed.');
            }

            return new ExternalManualPaymentAdapter;
        });
    }

    public function boot(): void
    {
        VenueBooking::observe(VenueBookingProjectionObserver::class);
        Event::listen(BookingProjectionUpdated::class, BroadcastVenueBookingProjectionUpdate::class);
        Event::listen(VenueBookingPolicyPublished::class, InvalidateVenueSearchAfterPolicyPublished::class);
        Event::listen(VenueBookingConfirmed::class, CreateEventFromConfirmedBookingIntent::class);
        Event::listen(VenueBookingMessageSent::class, NotifyVenueBookingMessageRecipients::class);
        Event::listen(VenueBookingRequested::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(VenueBookingHeld::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(VenueBookingConfirmed::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(VenueBookingRejected::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(VenueBookingCancelled::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(VenueBookingExpired::class, NotifyVenueBookingLifecycleRecipients::class);
        Event::listen(ContributionCommitmentSet::class, QueueContributionSummaryNotification::class);
        Event::listen(ContributionCommitmentWithdrawn::class, QueueContributionSummaryNotification::class);
    }
}
