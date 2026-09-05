<?php

namespace App\Modules\Venue\Domain\Events;

final readonly class VenueOwnershipClaimMessageSent
{
    public function __construct(public int $messageId) {}
}
