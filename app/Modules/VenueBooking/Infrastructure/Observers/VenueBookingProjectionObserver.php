<?php

namespace App\Modules\VenueBooking\Infrastructure\Observers;

use App\Modules\VenueBooking\Domain\Events\BookingProjectionUpdated;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Support\Facades\DB;

final class VenueBookingProjectionObserver
{
    public function created(VenueBooking $booking): void
    {
        $this->afterCommit($booking);
    }

    public function updated(VenueBooking $booking): void
    {
        if ($booking->wasChanged('optimistic_version')) {
            $this->afterCommit($booking);
        }
    }

    private function afterCommit(VenueBooking $booking): void
    {
        if ($booking->flow !== 'rental' || $booking->optimistic_version === null) {
            return;
        }

        $bookingId = $booking->id;
        $version = $booking->optimistic_version;
        DB::afterCommit(static fn () => event(new BookingProjectionUpdated($bookingId, $version)));
    }
}
