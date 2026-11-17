<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class EventVenueInterestMessageSentBroadcast implements ShouldBroadcastNow
{
    public function __construct(public string $conversationId, public string $messageId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('venue-booking-conversations.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'interest.message.sent';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }
}
