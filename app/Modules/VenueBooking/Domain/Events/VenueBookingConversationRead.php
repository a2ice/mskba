<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingConversationRead
{
    public function __construct(public int $bookingId, public int $conversationId, public int $userId, public ?int $messageId) {}
}
