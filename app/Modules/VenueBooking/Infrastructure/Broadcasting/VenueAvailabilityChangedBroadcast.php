<?php

namespace App\Modules\VenueBooking\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Public invalidation signal, never a public booking record. Clients refetch
 * authorized/public availability instead of trusting a socket payload.
 */
final readonly class VenueAvailabilityChangedBroadcast implements ShouldBroadcastNow
{
    public function __construct(
        public int $venueId,
        public ?int $courtId,
        public ?string $date = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('venue-availability.'.$this->venueId);
    }

    public function broadcastAs(): string
    {
        return 'availability.updated';
    }

    /** @return array{court_id: int|null, date: string|null} */
    public function broadcastWith(): array
    {
        return ['court_id' => $this->courtId, 'date' => $this->date];
    }
}
