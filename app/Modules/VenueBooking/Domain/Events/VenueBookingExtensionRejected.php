<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingExtensionRejected
{
    public function __construct(public int $bookingId, public int $extensionRequestId) {}
}
