<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Closure;
use Illuminate\Support\Facades\DB;

final class LockedVenueBooking
{
    /** @template T
     * @param  Closure(VenueBooking, Venue): T  $callback
     * @return T
     */
    public function run(int $bookingId, Closure $callback): mixed
    {
        $venueId = VenueBooking::query()->whereKey($bookingId)->value('venue_id');

        return DB::transaction(function () use ($bookingId, $venueId, $callback): mixed {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venueId);
            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($bookingId);

            if ($booking->venue_id !== $venue->id) {
                throw new \LogicException('Booking venue changed while acquiring locks.');
            }

            return $callback($booking, $venue);
        });
    }
}
