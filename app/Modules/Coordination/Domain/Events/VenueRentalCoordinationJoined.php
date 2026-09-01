<?php

namespace App\Modules\Coordination\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class VenueRentalCoordinationJoined
{
    use Dispatchable;

    public function __construct(public int $coordinationId, public int $userId) {}
}
