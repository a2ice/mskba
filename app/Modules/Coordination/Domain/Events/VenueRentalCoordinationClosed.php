<?php

namespace App\Modules\Coordination\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class VenueRentalCoordinationClosed
{
    use Dispatchable;

    public function __construct(public int $coordinationId) {}
}
