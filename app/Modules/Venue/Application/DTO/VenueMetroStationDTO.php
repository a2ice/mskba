<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueMetroStationDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $lineName,
        public ?string $lineColor,
        public ?string $latitude,
        public ?string $longitude,
        public ?int $distanceMeters,
        public ?int $walkingTimeMinutes,
    ) {}
}
