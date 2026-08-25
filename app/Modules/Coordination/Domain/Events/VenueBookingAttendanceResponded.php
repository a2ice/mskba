<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class VenueBookingAttendanceResponded
{
    public function __construct(public int $roundId, public int $userId, public string $response) {}
}
