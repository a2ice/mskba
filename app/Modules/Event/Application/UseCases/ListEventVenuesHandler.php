<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Collection;

final class ListEventVenuesHandler
{
    /** @return Collection<int, Venue> */
    public function handle(bool $freeOnly = false): Collection
    {
        return Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
            ->when($freeOnly, fn ($query) => $query
                ->where('requires_payment', false)
                ->where('requires_booking_approval', false))
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'alias',
                'raw_address',
                'requires_payment',
                'requires_booking_approval',
            ]);
    }
}
