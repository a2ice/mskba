<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Closure;
use Illuminate\Support\Facades\DB;

final class LockedVenueBooking
{
    public function __construct(private readonly VenueBookingConflictService $conflicts) {}

    /** @template T
     * @param  Closure(VenueBooking, Venue): T  $callback
     * @return T
     */
    public function run(int $bookingId, Closure $callback, bool $lockConflicts = false): mixed
    {
        $candidate = VenueBooking::query()->findOrFail($bookingId);

        return DB::transaction(function () use ($bookingId, $candidate, $callback, $lockConflicts): mixed {
            $venue = Venue::query()->lockForUpdate()->findOrFail($candidate->venue_id);

            if ($lockConflicts) {
                $this->conflicts->lockAndAssertAvailable($venue, $candidate);
            }

            $booking = VenueBooking::query()->lockForUpdate()->findOrFail($bookingId);

            if ($booking->venue_id !== $venue->id
                || ! $booking->starts_at->equalTo($candidate->starts_at)
                || ! $booking->ends_at->equalTo($candidate->ends_at)
                || $booking->scope !== $candidate->scope) {
                throw new \LogicException('Booking changed while acquiring locks.');
            }

            return $callback($booking, $venue);
        });
    }
}
