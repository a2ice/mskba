<?php

namespace App\Modules\VenueBooking\Infrastructure\Listeners;

use App\Modules\VenueBooking\Domain\Events\BookingProjectionUpdated;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingUpdatedBroadcast;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueAvailabilityChangedBroadcast;

final readonly class BroadcastVenueBookingProjectionUpdate
{
    public function handle(BookingProjectionUpdated $event): void
    {
        $booking = VenueBooking::query()->find($event->bookingId);
        if ($booking === null) {
            return;
        }

        broadcast(new VenueBookingUpdatedBroadcast($booking->public_id, $event->version));
        // The public channel only signals invalidation; no booking ID,
        // applicant identity or status is exposed to other visitors.
        broadcast(new VenueAvailabilityChangedBroadcast(
            (int) $booking->venue_id,
            $booking->venue_court_id === null ? null : (int) $booking->venue_court_id,
            $booking->starts_at->setTimezone('Europe/Moscow')->toDateString(),
        ));
    }
}
