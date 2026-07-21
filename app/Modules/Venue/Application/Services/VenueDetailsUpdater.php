<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use InvalidArgumentException;

final class VenueDetailsUpdater
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
        private readonly VenueTagSynchronizer $tagSynchronizer,
        private readonly VenueProximityService $proximity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames
     */
    public function update(Venue $venue, array $data, CreateLocationDTO $locationData, array $tagNames = []): Venue
    {
        $venue->loadMissing('location.address');
        $hasNewLocation = $locationData->hasData();
        $latitude = $hasNewLocation ? $locationData->latitude : $venue->location?->address?->latitude;
        $longitude = $hasNewLocation ? $locationData->longitude : $venue->location?->address?->longitude;

        if ($latitude === null || $longitude === null) {
            throw new InvalidArgumentException('Выберите адрес из подсказки, чтобы сохранить координаты площадки.');
        }

        $type = VenueTypeEnum::from($data['type']);

        if ($this->proximity->existsNearCoordinates(
            type: $type,
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            radiusMeters: $this->proximity->strongRadiusMeters(),
            statuses: [VenueStatusEnum::CONFIRMED],
            exceptVenueId: (int) $venue->id,
        )) {
            throw new InvalidArgumentException('Рядом уже существует подтвержденная площадка такого типа.');
        }

        $location = $hasNewLocation
            ? $this->createLocation->handle($locationData)
            : $venue->location;

        $venue->forceFill([
            'location_id' => $location?->id,
            'name' => $data['name'],
            'type' => $type->value,
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
            'raw_address' => $hasNewLocation
                ? ($locationData->rawAddress ?? $data['raw_address'] ?? null)
                : $venue->raw_address,
            'content_version' => $venue->content_version + 1,
        ])->save();

        $this->tagSynchronizer->sync($venue, $tagNames);

        return $venue->refresh();
    }
}
