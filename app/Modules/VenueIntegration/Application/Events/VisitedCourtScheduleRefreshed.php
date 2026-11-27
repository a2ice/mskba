<?php

namespace App\Modules\VenueIntegration\Application\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Public invalidation only: no source configuration, slots or personal data. */
final class VisitedCourtScheduleRefreshed implements ShouldBroadcastNow
{
    public function __construct(public readonly int $venueId) {}

    public function broadcastOn(): Channel
    {
        return new Channel('venue-schedule.'.$this->venueId);
    }

    public function broadcastAs(): string
    {
        return 'schedule.refreshed';
    }

    public function broadcastWith(): array
    {
        return ['venue_id' => $this->venueId];
    }
}
