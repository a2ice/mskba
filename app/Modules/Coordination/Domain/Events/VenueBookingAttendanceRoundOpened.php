<?php

namespace App\Modules\Coordination\Domain\Events;

final readonly class VenueBookingAttendanceRoundOpened
{
    public function __construct(public int $roundId) {}
}
