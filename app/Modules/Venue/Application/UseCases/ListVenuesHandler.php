<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class ListVenuesHandler
{
    public function __construct(
        private readonly VenueAccessResolver $accessResolver,
        private readonly ListVenuesBuilder $listVenuesBuilder,
    ) {}

    public function handle(?User $user): array
    {
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);
        $bootstrapOwnedVenueIds = $this->accessResolver->bootstrapOwnedVenueIdsFor($user);

        return $this->listVenuesBuilder->build(function ($query) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds): void {
            $query->where('status', VenueStatusEnum::CONFIRMED->value)
                ->orWhereIn('id', $contractViewableVenueIds)
                ->orWhereIn('id', $bootstrapOwnedVenueIds);
        })
            ->get()
            ->map(function (Venue $venue) use ($contractViewableVenueIds, $contractEditableVenueIds, $contractScheduleEditableVenueIds, $bootstrapOwnedVenueIds) {
                $isBootstrapOwned = in_array($venue->id, $bootstrapOwnedVenueIds, true);

                return new VenueListItemDTO(
                    id: $venue->id,
                    name: $venue->name,
                    alias: $venue->alias,
                    type: $venue->type->label(),
                    status: $venue->status->label(),
                    description: $venue->description,
                    rawAddress: $venue->raw_address,
                    canView: $venue->status === VenueStatusEnum::CONFIRMED || $isBootstrapOwned || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $isBootstrapOwned || in_array($venue->id, $contractEditableVenueIds, true),
                    canEditSchedule: $isBootstrapOwned || in_array($venue->id, $contractScheduleEditableVenueIds, true),
                );
            })
            ->all();
    }
}
