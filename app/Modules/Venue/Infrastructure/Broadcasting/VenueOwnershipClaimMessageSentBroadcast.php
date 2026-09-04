<?php

namespace App\Modules\Venue\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class VenueOwnershipClaimMessageSentBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        public string $claimId,
        public string $conversationId,
        public string $messageId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('venue-ownership-claims.'.$this->claimId),
            new PrivateChannel('venue-ownership-claim-conversations.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'venue.ownership.message.sent';
    }

    /** @return array{claim_id: string, conversation_id: string, message_id: string} */
    public function broadcastWith(): array
    {
        return [
            'claim_id' => $this->claimId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
        ];
    }
}
