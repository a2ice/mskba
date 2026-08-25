<?php

namespace App\Modules\Telegram\Infrastructure\Providers;

use App\Modules\Coordination\Domain\Events\PollActivated;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationClosed;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationConverted;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationCreated;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationJoined;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Notification\Domain\Events\UserNotificationCreated;
use App\Modules\Telegram\Infrastructure\Listeners\PrepareActivatedPollPublications;
use App\Modules\Telegram\Infrastructure\Listeners\PrepareTelegramVenueRentalPublications;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramCoordinationPublicationSync;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramEventPublicationSync;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramVenueRentalBookingSync;
use App\Modules\Telegram\Infrastructure\Listeners\QueueTelegramVenueRentalPublicationSync;
use App\Modules\Telegram\Infrastructure\Listeners\QueueUserNotificationTelegramDelivery;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRequested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class TelegramServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(EventChanged::class, QueueTelegramEventPublicationSync::class);
        Event::listen(PollChanged::class, QueueTelegramCoordinationPublicationSync::class);
        Event::listen(PollActivated::class, PrepareActivatedPollPublications::class);
        Event::listen(UserNotificationCreated::class, QueueUserNotificationTelegramDelivery::class);
        Event::listen(VenueRentalCoordinationCreated::class, PrepareTelegramVenueRentalPublications::class);
        Event::listen(VenueRentalCoordinationJoined::class, QueueTelegramVenueRentalPublicationSync::class);
        Event::listen(VenueRentalCoordinationClosed::class, QueueTelegramVenueRentalPublicationSync::class);
        Event::listen(VenueRentalCoordinationConverted::class, QueueTelegramVenueRentalPublicationSync::class);
        Event::listen(VenueBookingRequested::class, QueueTelegramVenueRentalBookingSync::class);
        Event::listen(VenueBookingHeld::class, QueueTelegramVenueRentalBookingSync::class);
        Event::listen(VenueBookingConfirmed::class, QueueTelegramVenueRentalBookingSync::class);
        Event::listen(VenueBookingRejected::class, QueueTelegramVenueRentalBookingSync::class);
        Event::listen(VenueBookingCancelled::class, QueueTelegramVenueRentalBookingSync::class);
        Event::listen(VenueBookingExpired::class, QueueTelegramVenueRentalBookingSync::class);
    }
}
