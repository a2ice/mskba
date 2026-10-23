<?php

namespace App\Modules\Venue\Presentation\Http\Resources;

use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Domain\Models\Venue;
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
        $photo = $venue->media->first();

        return [
            'id' => (int) $venue->id,
            'name' => $venue->name,
            'alias' => $venue->alias,
            'type' => $venue->type->value,
            'type_label' => $venue->type->label(),
            'status' => $venue->status->value,
            'operational_status' => $venue->operational_status->value,
            'requires_payment' => $venue->requires_payment,
            'requires_booking_approval' => $venue->requires_booking_approval,
            'has_free_access' => $venue->hasFreeAccess(),
            'short_description' => $venue->short_description,
            'address' => $this->addressFormatter->format(
                $address?->full_address ?? $venue->raw_address,
                $address?->city,
                $address?->street,
                $address?->building,
            ),
            'latitude' => $address?->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address?->longitude === null ? null : (float) $address->longitude,
            'image_url' => $photo === null ? null : url($photo->publicUrl()),
            'url' => route('venues.show', $venue->routeIdentifier()),
        ];
    }
}
