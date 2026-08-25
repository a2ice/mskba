<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingHeld
{
    public function __construct(public int $bookingId) {}
}
