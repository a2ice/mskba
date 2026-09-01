<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingConfirmed
{
    public function __construct(public int $bookingId, public ?string $messageId = null) {}
}
