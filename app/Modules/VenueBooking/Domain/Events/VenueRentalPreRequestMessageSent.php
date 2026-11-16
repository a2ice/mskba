<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueRentalPreRequestMessageSent
{
    public function __construct(public int $messageId) {}
}
