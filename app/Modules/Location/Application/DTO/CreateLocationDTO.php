<?php

namespace App\Modules\Location\Application\DTO;

final readonly class CreateLocationDTO
{
    /**
     * @param  array<int>  $metroStationIds
     */
    public function __construct(
        public ?string $rawAddress = null,
        public ?string $city = null,
        public ?string $street = null,
        public ?string $building = null,
        public ?string $postalCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $metroStationIds = [],
    ) {}

    public function hasData(): bool
    {
        return $this->rawAddress !== null
            || $this->city !== null
            || $this->street !== null
            || $this->building !== null
            || $this->metroStationIds !== [];
    }

    public function hasStructuredAddress(): bool
    {
        return $this->city !== null
            || $this->street !== null
            || $this->building !== null
            || $this->latitude !== null
            || $this->longitude !== null;
    }
}
