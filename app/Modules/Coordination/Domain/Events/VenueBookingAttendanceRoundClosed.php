<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class VenueBookingAttendanceRoundClosed
{
    public function __construct(public int $roundId, public string $reason) {}
}
