<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueCourtDiscoveryAccess;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

final class ListVenuesHandler
{
    public function __construct(
        private readonly VenueAccessResolver $accessResolver,
        private readonly ListVenuesBuilder $listVenuesBuilder,
        private readonly AddressDisplayFormatter $addressFormatter,
        private readonly VenueCourtDiscoveryAccess $courtDiscovery,
    ) {}

    /** @param array{search?: string|null, type?: string|null, operational_status?: string|null, access?: string|null, sort?: string|null} $filters */
    public function handle(?User $user, ?Actor $actor = null, array $filters = []): array
    {
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);
        $bootstrapOwnedVenueIds = $this->accessResolver->bootstrapOwnedVenueIdsFor($user);
        $actorOwnedVenueIds = $this->accessResolver->actorOwnedVenueIdsFor($actor);

        $venues = $this->listVenuesBuilder->build(function ($query) use ($contractViewableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds, $filters): void {
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
                ->when($filters['operational_status'] ?? null, fn ($filtered, string $status) => $filtered->where('operational_status', $status));
        })
            ->with('courts.media')
            ->when(
                ($filters['sort'] ?? null) === 'new',
                fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
                function ($query) use ($filters): void {
                    $direction = ($filters['sort'] ?? null) === 'name_desc' ? 'desc' : 'asc';
                    $query->orderBy('name', $direction)->orderBy('id');
                },
            )
            ->get();

        $policies = VenueBookingPolicy::query()
            ->whereIn('venue_id', $venues->pluck('id')->all())
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->get()
            ->keyBy('venue_id');

        $accessFilter = $filters['access'] ?? null;

        return $venues
            ->map(fn (Venue $venue): array => [
                'venue' => $venue,
                'access' => $this->courtDiscovery->classify($venue, $policies->get($venue->id)),
            ])
            ->filter(fn (array $row): bool => $accessFilter === null
                || $accessFilter === ''
                || ! empty($row['access'][$accessFilter]))
            ->map(function (array $row) use (
                $accessFilter, $contractViewableVenueIds, $contractEditableVenueIds,
                $contractScheduleEditableVenueIds, $bootstrapOwnedVenueIds, $actorOwnedVenueIds,
            ) {
                /** @var Venue $venue */
                $venue = $row['venue'];
                $access = $row['access'];
                $matched = $accessFilter ? ($access[$accessFilter][0] ?? null) : null;
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
                    displayAddress: trim((string) $venue->raw_address) !== ''
                        ? $this->addressFormatter->format($venue->raw_address)
                        : $this->addressFormatter->format(
                            $venue->location?->address?->full_address,
                            $venue->location?->address?->city,
                            $venue->location?->address?->street,
                            $venue->location?->address?->building,
                        ),
                    imageUrl: $venue->representativePhoto()?->publicUrl(),
                    latitude: $venue->location?->address?->latitude !== null ? (float) $venue->location->address->latitude : null,
                    longitude: $venue->location?->address?->longitude !== null ? (float) $venue->location->address->longitude : null,
                    canView: $venue->status === VenueStatusEnum::CONFIRMED || $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $venue->allowsDetailsEditing() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true)),
                    canEditSchedule: $venue->allowsOperationalChanges() && ($isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractScheduleEditableVenueIds, true)),
                    canRemove: $isBootstrapOwned || $isActorOwned || in_array($venue->id, $contractEditableVenueIds, true),
                    courtAccessSummary: $access,
                    matchedCourtUrl: $matched['url'] ?? null,
                    matchedCourtName: $matched['name'] ?? null,
                    matchedRequiresBooking: $matched['requires_booking'] ?? null,
                    matchedRequiresApproval: $matched['requires_booking_approval'] ?? null,
                );
            })
            ->all();
    }
}
