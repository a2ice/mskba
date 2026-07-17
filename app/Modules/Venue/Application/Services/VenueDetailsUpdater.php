<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Location\Application\UseCases\CreateLocationHandler;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;

final class VenueDetailsUpdater
{
    public function __construct(
        private readonly CreateLocationHandler $createLocation,
        private readonly VenueTagSynchronizer $tagSynchronizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $tagNames
     */
    public function update(Venue $venue, array $data, CreateLocationDTO $locationData, array $tagNames = []): Venue
    {
        $location = $this->createLocation->handle($locationData);

        $venue->forceFill([
            'location_id' => $location?->id,
            'name' => $data['name'],
            'type' => VenueTypeEnum::from($data['type'])->value,
            'requires_payment' => (bool) ($data['requires_payment'] ?? $venue->requires_payment),
            'requires_booking_approval' => (bool) ($data['requires_booking_approval'] ?? $venue->requires_booking_approval),
            'short_description' => $data['short_description'] ?? null,
            'full_description' => $data['full_description'] ?? null,
            'raw_address' => $locationData->rawAddress ?? $data['raw_address'] ?? null,
        ])->save();

        $this->tagSynchronizer->sync($venue, $tagNames);

        return $venue->refresh();
    }
}
