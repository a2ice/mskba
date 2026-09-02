<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Domain\Events\BookingProjectionUpdated;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingUpdatedBroadcast;

final readonly class BroadcastVenueBookingProjectionUpdate
{
    public function handle(BookingProjectionUpdated $event): void
    {
        $publicId = VenueBooking::query()->whereKey($event->bookingId)->value('public_id');
        if (! is_string($publicId)) {
            return;
        }

        broadcast(new VenueBookingUpdatedBroadcast($publicId, $event->version));
    }
}
