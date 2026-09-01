<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueOwnershipClaimApproved
{
    public function __construct(public int $claimId) {}
}
