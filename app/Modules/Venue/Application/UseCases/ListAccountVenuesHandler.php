<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\Builders\ListVenuesBuilder;
use App\Modules\Venue\Application\DTO\VenueListItemDTO;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Queries\CountActionableVenueBookingRequests;

final class ListAccountVenuesHandler
{
    public function __construct(
        private readonly VenueAccessResolver $accessResolver,
        private readonly ListVenuesBuilder $listVenuesBuilder,
        private readonly AddressDisplayFormatter $addressFormatter,
        private readonly CountActionableVenueBookingRequests $bookingRequests,
        private readonly VenueMembershipAccess $memberships,
    ) {}

    public function handle(?User $user): array
    {
        $contractedVenueIds = $this->accessResolver->contractedVenueIdsFor($user);
        $contractViewableVenueIds = $this->accessResolver->contractViewableVenueIdsFor($user);
        $contractEditableVenueIds = $this->accessResolver->contractEditableVenueIdsFor($user);
        $contractScheduleEditableVenueIds = $this->accessResolver->contractScheduleEditableVenueIdsFor($user);
        $bootstrapOwnedVenueIds = $this->accessResolver->bootstrapOwnedVenueIdsFor($user);
        $bookingRequestCounts = $user === null ? [] : $this->bookingRequests->byVenueFor($user);
        $decidableVenueIds = $user === null ? [] : $this->memberships
            ->allowedVenueIdsFor($user, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS);
        $isSuperadmin = $user?->canonical()->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false;

        return $this->listVenuesBuilder->build(function ($query) use ($contractedVenueIds, $bootstrapOwnedVenueIds): void {
            $query
                ->whereIn('id', $contractedVenueIds)
                ->orWhereIn('id', $bootstrapOwnedVenueIds);
        })
            ->get()
            ->map(function (Venue $venue) use ($contractViewableVenueIds, $contractEditableVenueIds, $contractScheduleEditableVenueIds, $bootstrapOwnedVenueIds, $bookingRequestCounts, $decidableVenueIds, $isSuperadmin) {
                $isBootstrapOwned = in_array($venue->id, $bootstrapOwnedVenueIds, true);

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
                    canView: $isBootstrapOwned || in_array($venue->id, $contractViewableVenueIds, true),
                    canEdit: $venue->allowsDetailsEditing() && ($isBootstrapOwned || in_array($venue->id, $contractEditableVenueIds, true)),
                    canEditSchedule: $venue->allowsOperationalChanges() && ($isBootstrapOwned || in_array($venue->id, $contractScheduleEditableVenueIds, true)),
                    canRemove: $isBootstrapOwned || in_array($venue->id, $contractEditableVenueIds, true),
                    canDecideBookingRequests: $isSuperadmin || in_array($venue->id, $decidableVenueIds, true),
                    actionableBookingRequestsCount: $bookingRequestCounts[$venue->id] ?? 0,
                );
            })
            ->all();
    }
}
