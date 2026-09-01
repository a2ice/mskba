<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class ContributionCommitmentWithdrawn
{
    public function __construct(public int $bookingId, public int $commitmentId) {}
}
