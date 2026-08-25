<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueOwnershipClaimRejected
{
    public function __construct(public int $claimId) {}
}
