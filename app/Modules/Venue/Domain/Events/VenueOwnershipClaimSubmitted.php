<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueOwnershipClaimSubmitted
{
    public function __construct(public int $claimId) {}
}
