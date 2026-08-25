<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingRejected
{
    public function __construct(public int $bookingId) {}
}
