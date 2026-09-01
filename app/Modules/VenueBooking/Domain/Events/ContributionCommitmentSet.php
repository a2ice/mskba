<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class ContributionCommitmentSet
{
    public function __construct(public int $bookingId, public int $commitmentId) {}
}
