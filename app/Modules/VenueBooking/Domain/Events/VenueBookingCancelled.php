<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingCancelled
{
    public function __construct(public int $bookingId) {}
}
