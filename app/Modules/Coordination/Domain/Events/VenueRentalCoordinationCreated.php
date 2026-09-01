<?php

namespace App\Modules\Coordination\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class VenueRentalCoordinationCreated
{
    use Dispatchable;

    public function __construct(public int $coordinationId) {}
}
