<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueAddressDTO
{
    public function __construct(
        public ?string $city,
        public ?string $street,
        public ?string $building,
        public ?string $postalCode,
        public ?string $latitude,
        public ?string $longitude,
        public ?string $fullAddress,
        public string $display,
    ) {}
}
