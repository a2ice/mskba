<?php

namespace App\Modules\Venue\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final readonly class VenueOwnershipClaimUpdatedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        public string $claimId,
        public string $status,
        public string $statusLabel,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('venue-ownership-claims.'.$this->claimId);
    }

    public function broadcastAs(): string
    {
        return 'venue.ownership.updated';
    }

    /** @return array{claim_id: string, status: string, status_label: string} */
    public function broadcastWith(): array
    {
        return [
            'claim_id' => $this->claimId,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
        ];
    }
}
