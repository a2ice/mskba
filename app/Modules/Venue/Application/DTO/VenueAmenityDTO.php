<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueAmenityDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public ?string $description,
        public ?string $icon,
        public ?string $note,
    ) {}
}
