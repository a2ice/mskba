<?php

namespace App\Modules\Location\Application\UseCases;

use App\Modules\Location\Application\DTO\MetroStationOptionDTO;
use App\Modules\Location\Domain\Models\MetroStation;
use Illuminate\Support\Collection;

final class ListMetrostationsHandler
{
    /**
     * @return Collection<int, MetroStationOptionDTO>
     */
    public function handle(): Collection
    {
        return MetroStation::query()
            ->with('line')
            ->orderBy('name')
            ->get()
            ->map(fn (MetroStation $station) => new MetroStationOptionDTO(
                id: (int) $station->id,
                name: $station->name,
                lineName: $station->line?->name,
                lineColor: $station->line?->color,
            ))
            ->values();
    }
}
