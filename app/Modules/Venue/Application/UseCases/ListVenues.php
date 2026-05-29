<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;

final class ListVenues
{
    public function handle(?User $user): array
    {
        return Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (Venue $venue) => new VenueListItemDTO(
                id: $venue->id,
                name: $venue->name,
                alias: $venue->alias,
                status: $venue->status->label(),
                description: $venue->description,
                canEdit: false,
            ))
            ->all();
    }
}