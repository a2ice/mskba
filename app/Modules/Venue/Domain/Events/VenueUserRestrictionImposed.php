<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueUserRestrictionImposed
{
    public function __construct(public int $restrictionId) {}
}
