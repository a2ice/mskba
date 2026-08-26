<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class BookingProjectionUpdated
{
    public function __construct(public int $bookingId, public int $version) {}
}
