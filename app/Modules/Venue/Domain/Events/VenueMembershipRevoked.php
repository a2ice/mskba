<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueMembershipRevoked
{
    public function __construct(public int $membershipId) {}
}
