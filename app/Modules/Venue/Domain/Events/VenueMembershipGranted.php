<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueMembershipGranted
{
    public function __construct(public int $membershipId) {}
}
