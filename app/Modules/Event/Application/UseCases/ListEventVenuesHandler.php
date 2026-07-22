<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Collection;

final class ListEventVenuesHandler
{
    /** @return Collection<int, Venue> */
    public function handle(): Collection
    {
        return Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
            ->whereHas('schedule', fn ($query) => $query->whereHas('intervals')->orWhereHas('exceptions.intervals'))
            ->orderBy('name')
            ->get(['id', 'name', 'alias', 'raw_address']);
    }
}
