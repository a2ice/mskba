<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingMessageSent
{
    public function __construct(public int $bookingId, public int $conversationId, public int $messageId) {}
}
