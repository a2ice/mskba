<?php

namespace App\Modules\Venue\Presentation\Http\Resources;

use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Application\Services\VenueCourtDiscoveryAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Venue */
final class PublicVenueResource extends JsonResource
{
    public function __construct(
        Venue $venue,
        private readonly AddressDisplayFormatter $addressFormatter,
    ) {
        parent::__construct($venue);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Venue $venue */
        $venue = $this->resource;
        $address = $venue->location?->address;
        $photo = $venue->representativePhoto();
        $venue->loadMissing(['courts.media', 'activeBookingPolicy']);
        $courtAccess = app(VenueCourtDiscoveryAccess::class)->classify($venue, $venue->activeBookingPolicy, includeClosed: true);
        $free = $courtAccess['free'] !== [];
        $paid = $courtAccess['paid'] !== [];
        $approval = $courtAccess['approval'] !== [];
        $walkIn = collect($courtAccess['free'])->contains(fn (array $court): bool =>
            $court['is_open'] && ! $court['requires_booking'] && ! $court['requires_booking_approval']
        );
        $environment = VenueTypeEnum::tryFrom((string) $request->query('type', ''))?->discoveryEnvironment();
        $access = $request->query('access');
        $selected = VenueCourtDiscoveryAccess::select(
            $courtAccess,
            $environment?->value,
            VenueCourtDiscoveryAccess::paymentFilter($access),
            $access === 'approval' ? true : null,
            $request->query('operational_status'),
        );

        return [
            'id' => (int) $venue->id,
            'name' => $venue->name,
            'alias' => $venue->alias,
            'type' => $venue->type->value,
            'type_label' => $venue->type->label(),
            'status' => $venue->status->value,
            'operational_status' => $venue->operational_status->value,
            'requires_payment' => $paid && ! $free ? true : ($free && ! $paid ? false : null),
            'requires_booking_approval' => $approval,
            'has_free_access' => $walkIn,
            'has_free_court' => $free,
            'has_paid_court' => $paid,
            'has_approval_court' => $approval,
            'court_access' => $courtAccess,
            'matched_court' => $selected,
            'court_count' => $venue->courts->count(),
            'short_description' => $venue->short_description,
            'address' => trim((string) $venue->raw_address) !== ''
                ? $this->addressFormatter->format($venue->raw_address)
                : $this->addressFormatter->format(
                    $address?->full_address,
                    $address?->city,
                    $address?->street,
                    $address?->building,
                ),
            'latitude' => $address?->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address?->longitude === null ? null : (float) $address->longitude,
            'image_url' => $selected['image_url'] ?? ($photo === null ? null : url($photo->publicUrl())),
            'url' => $selected['url'] ?? route('venues.show', $venue->routeIdentifier()),
            'venue_url' => route('venues.show', $venue->routeIdentifier()),
        ];
    }
}
