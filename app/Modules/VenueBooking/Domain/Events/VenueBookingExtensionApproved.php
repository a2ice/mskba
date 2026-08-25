<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingExtensionApproved
{
    public function __construct(public int $bookingId, public int $extensionRequestId) {}
}
