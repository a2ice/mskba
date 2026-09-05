<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
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
        private readonly AddressDisplayFormatter $addressFormatter,
    ) {}

    /** @param array{search?: string|null, type?: string|null, operational_status?: string|null, access?: string|null} $filters */
    public function handle(?User $user, ?Actor $actor = null, array $filters = []): array
    {
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);
        $bootstrapOwnedVenueIds = $this->accessResolver->bootstrapOwnedVenueIdsFor($user);
        $actorOwnedVenueIds = $this->accessResolver->actorOwnedVenueIdsFor($actor);

        return $this->listVenuesBuilder->build(function ($query) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds, $filters): void {
            $query->where(function ($visible) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds): void {
                $visible->where('status', VenueStatusEnum::CONFIRMED->value)
                    ->orWhereIn('id', $contractViewableVenueIds)
                    ->orWhereIn('id', $bootstrapOwnedVenueIds)
                    ->orWhereIn('id', $actorOwnedVenueIds);
            });

            $query
                ->when($filters['search'] ?? null, function ($filtered, string $search): void {
                    $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    foreach ($terms as $term) {
                        $needle = '%'.addcslashes($term, '%_\\').'%';
                        $filtered->where(fn ($match) => $match
                            ->whereLike('name', $needle)
                            ->orWhereLike('raw_address', $needle)
                            ->orWhereLike('short_description', $needle));
                    }
                })
                ->when($filters['type'] ?? null, fn ($filtered, string $type) => $filtered->where('type', $type))
                ->when($filters['operational_status'] ?? null, fn ($filtered, string $status) => $filtered->where('operational_status', $status))
                ->when(($filters['access'] ?? null) === 'free', fn ($filtered) => $filtered->where('requires_payment', false)->where('requires_booking_approval', false))
                ->when(($filters['access'] ?? null) === 'paid', fn ($filtered) => $filtered->where('requires_payment', true))
                ->when(($filters['access'] ?? null) === 'approval', fn ($filtered) => $filtered->where('requires_booking_approval', true));
        })
            ->orderBy('name')
            ->get()
            ->map(function (Venue $venue) use ($contractViewableVenueIds, $contractEditableVenueIds, $contractScheduleEditableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds) {
                $isBootstrapOwned = in_array($venue->id, $bootstrapOwnedVenueIds, true);
                $isActorOwned = in_array($venue->id, $actorOwnedVenueIds, true);

                return new VenueListItemDTO(
                    id: $venue->id,
                    name: $venue->name,
                    alias: $venue->alias,
                    type: $venue->type->label(),
                    typeSlug: $venue->type->value,
                    operationalStatus: $venue->operational_status->label(),
                    operationalStatusSlug: $venue->operational_status->value,
                    requiresPayment: $venue->requires_payment,
                    requiresBookingApproval: $venue->requires_booking_approval,
                    status: $venue->status->label(),
                    statusSlug: $venue->status->value,
                    shortDescription: $venue->short_description,
                    rawAddress: $venue->raw_address,
                    displayAddress: $this->addressFormatter->format(
                        $venue->location?->address?->full_address ?? $venue->raw_address,
                        $venue->location?->address?->city,
                        $venue->location?->address?->street,
                        $venue->location?->address?->building,
                    ),
                    imageUrl: $venue->media->first()?->publicUrl(),
                    latitude: $venue->location?->address?->latitude !== null ? (float) $venue->location->address->latitude : null,
                    longitude: $venue->location?->address?->longitude !== null ? (float) $venue->location->address->longitude : null,
                    canView: $venue->status === VenueStatusEnum::CONFIRMED || $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $venue->allowsDetailsEditing() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true)),
                    canEditSchedule: $venue->allowsOperationalChanges() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractScheduleEditableVenueIds, true)),
                    canRemove: $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true),
                );
            })
            ->all();
    }
}
