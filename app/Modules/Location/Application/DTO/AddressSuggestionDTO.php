<?php

namespace App\Modules\Location\Application\DTO;

final readonly class AddressSuggestionDTO
{
    /**
     * @param  array<int, string>  $metroNames
     * @param  array<int>  $metroStationIds
     * @param  array<int, string>  $metroStationLabels
     */
    public function __construct(
        public string $label,
        public ?string $country,
        public ?string $city,
        public ?string $street,
        public ?string $building,
        public ?string $postalCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public array $metroNames = [],
        public array $metroStationIds = [],
        public array $metroStationLabels = [],
    ) {}

    public function hasHouse(): bool
    {
        return $this->building !== null && trim($this->building) !== '';
    }
}
