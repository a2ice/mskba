<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class VenueBookingMessageSentBroadcast implements ShouldBroadcastNow
{
    public function __construct(public string $bookingId, public string $conversationId, public string $messageId) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('venue-bookings.'.$this->bookingId),
            new PrivateChannel('venue-booking-conversations.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'booking.message.sent';
    }

    /** @return array{booking_id: string, conversation_id: string, message_id: string} */
    public function broadcastWith(): array
    {
        return ['booking_id' => $this->bookingId, 'conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }
}
