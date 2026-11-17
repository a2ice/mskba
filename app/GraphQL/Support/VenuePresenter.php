<?php

namespace App\GraphQL\Support;

use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Resources\PublicVenueResource;
use Illuminate\Http\Request;

final class VenuePresenter
{
    /** @return array<string, mixed> */
    public static function present(Venue $venue, AddressDisplayFormatter $formatter, Request $request): array
    {
        $data = (new PublicVenueResource($venue, $formatter))->toArray($request);

        return [
            'id' => (string) $data['id'],
            'name' => $data['name'],
            'alias' => $data['alias'],
            'type' => $data['type'],
            'typeLabel' => $data['type_label'],
            'status' => $data['status'],
            'operationalStatus' => $data['operational_status'],
            'shortDescription' => $data['short_description'],
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'imageUrl' => $data['image_url'],
            'url' => $data['url'],
        ];
    }
}
