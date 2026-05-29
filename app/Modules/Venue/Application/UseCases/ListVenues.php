<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class ListVenues
{
    public function __construct(
        private readonly VenueAccessResolver $access,
    ) {}

    public function handle(?User $user): array
    {
        $contractViewableVenueIds = $this->access->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->access->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->access->contractScheduleEditableVenueIdsFor($user);

        return Venue::query()
            ->where(function ($query) use ($contractViewableVenueIds): void {
                $query->where('status', VenueStatusEnum::CONFIRMED->value);

                if ($contractViewableVenueIds !== []) {
                    $query->orWhereIn('id', $contractViewableVenueIds);
                }
            })
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (Venue $venue) => new VenueListItemDTO(
                id: $venue->id,
                name: $venue->name,
                alias: $venue->alias,
                status: $venue->status->label(),
                description: $venue->description,
                canView: true,
                canEdit: in_array($venue->id, $contractEditableVenueIds, true),
                canEditSchedule: in_array($venue->id, $contractScheduleEditableVenueIds, true),
            ))
            ->all();
    }
}
