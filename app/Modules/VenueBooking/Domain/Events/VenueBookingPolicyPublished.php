<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingPolicyPublished
{
    public function __construct(
        public int $policyId,
        public int $venueId,
    ) {}
}
