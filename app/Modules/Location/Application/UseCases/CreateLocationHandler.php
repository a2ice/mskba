<?php

namespace App\Modules\Location\Application\UseCases;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\Location;
use InvalidArgumentException;

final class CreateLocationHandler
{
    public function handle(CreateLocationDTO $data): ?Location
    {
        if (! $data->hasData()) {
            return null;
        }

        [$cityId, $districtId, $cityName] = $this->resolveDirectoryReferences($data);

        $address = ! $this->hasAddressData($data)
            ? null
            : Address::query()->create([
                'city_id' => $cityId,
                'district_id' => $districtId,
                'city' => $cityName,
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

    /**
     * @return array{0: int|null, 1: int|null, 2: string}
     */
    private function resolveDirectoryReferences(CreateLocationDTO $data): array
    {
        $cityId = $data->cityId;
        $districtId = $data->districtId;
        $cityModel = null;

        if ($cityId !== null) {
            $cityModel = City::query()->find($cityId);
            if ($cityModel === null) {
                throw new InvalidArgumentException('Указанный город не найден.');
            }
        }

        if ($districtId !== null) {
            $district = District::query()->find($districtId);
            if ($district === null) {
                throw new InvalidArgumentException('Указанный район не найден.');
            }

            if ($cityId !== null && (int) $district->city_id !== $cityId) {
                throw new InvalidArgumentException('Выбранный район не относится к указанному городу.');
            }

            if ($cityId === null) {
                $cityId = (int) $district->city_id;
                $cityModel = $district->city;
            }
        }

        $cityName = $data->city
            ?? $cityModel?->name
            ?? (string) config('integrations.address.default_city', 'Москва');

        return [$cityId, $districtId, $cityName];
    }
}
