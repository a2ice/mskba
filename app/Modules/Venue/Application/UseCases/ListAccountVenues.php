<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Models\Venue;

final class ListAccountVenues
{
    public function __construct(
        private readonly VenueAccessResolver $accessResolver,
        private readonly ListVenuesBuilder $listVenuesBuilder,
    ) {}

    public function handle(?User $user): array
    {
        $contractedVenueIds = $this->accessResolver->contractedVenueIdsFor($user);
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);

        return $this->listVenuesBuilder->build(function ($query) use ($contractedVenueIds): void {
            $query->whereIn('id', $contractedVenueIds);
        })
            ->get()
            ->map(function (Venue $venue) use ($contractViewableVenueIds, $contractEditableVenueIds, $contractScheduleEditableVenueIds) {
                return new VenueListItemDTO(
                    id: $venue->id,
                    name: $venue->name,
                    alias: $venue->alias,
                    type: $venue->type->label(),
                    status: $venue->status->label(),
                    description: $venue->description,
                    canView: in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: in_array($venue->id, $contractEditableVenueIds, true),
                    canEditSchedule: in_array($venue->id, $contractScheduleEditableVenueIds, true),
                );
            })
            ->all();
    }
}
