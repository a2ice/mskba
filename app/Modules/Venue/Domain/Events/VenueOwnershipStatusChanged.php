<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueOwnershipStatusChanged
{
    public function __construct(public int $ownershipId) {}
}
