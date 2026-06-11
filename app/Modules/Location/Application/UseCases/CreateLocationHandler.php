<?php

namespace App\Modules\Location\Application\UseCases;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;

final class CreateLocationHandler
{
    public function handle(CreateLocationDTO $data): ?Location
    {
        if (! $data->hasData()) {
            return null;
        }

        $address = ! $this->hasAddressData($data)
            ? null
            : Address::query()->create([
                'city' => $data->city ?? config('integrations.address.default_city', 'Москва'),
                'street' => $data->street,
                'building' => $data->building,
                'postal_code' => $data->postalCode,
                'latitude' => $data->latitude,
                'longitude' => $data->longitude,
                'full_address' => $data->rawAddress,
            ]);

        $location = Location::query()->create([
            'address_id' => $address?->id,
        ]);

        if ($data->metroStationIds !== []) {
            $location->metroStations()->sync($data->metroStationIds);
        }

        return $location->load('address', 'metroStations.line');
    }

    private function hasAddressData(CreateLocationDTO $data): bool
    {
        return $data->rawAddress !== null || $data->hasStructuredAddress();
    }
}
