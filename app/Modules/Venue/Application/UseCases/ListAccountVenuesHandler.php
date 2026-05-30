<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Models\Venue;

final class ListAccountVenuesHandler
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

        return $this->listVenuesBuilder->build(function ($query) use ($contractedVenueIds, $user): void {
            $query
                ->whereIn('id', $contractedVenueIds)
                ->when($user !== null, fn ($query) => $query->orWhere('created_by_user_id', $user->id));
        })
            ->get()
            ->map(function (Venue $venue) use ($user, $contractViewableVenueIds, $contractEditableVenueIds, $contractScheduleEditableVenueIds) {
                return new VenueListItemDTO(
                    id: $venue->id,
                    name: $venue->name,
                    alias: $venue->alias,
                    type: $venue->type->label(),
                    status: $venue->status->label(),
                    description: $venue->description,
                    canView: $this->isOwnedByCurrentUser($venue, $user) || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $this->isOwnedByCurrentUser($venue, $user) || in_array($venue->id, $contractEditableVenueIds, true),
                    canEditSchedule: in_array($venue->id, $contractScheduleEditableVenueIds, true),
                );
            })
            ->all();
    }

    private function isOwnedByCurrentUser(Venue $venue, ?User $user): bool
    {
        return $user !== null && $venue->created_by_user_id === $user->id;
    }
}
