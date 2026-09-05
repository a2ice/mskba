<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueUserRestrictionRevoked
{
    public function __construct(public int $restrictionId) {}
}
