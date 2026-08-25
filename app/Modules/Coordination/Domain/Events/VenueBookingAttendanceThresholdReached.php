<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class VenueBookingAttendanceThresholdReached
{
    public function __construct(public int $roundId, public int $yesCount) {}
}
