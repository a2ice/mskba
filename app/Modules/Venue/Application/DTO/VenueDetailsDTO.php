<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueDetailsDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $type,
        public string $status,
        public ?string $description,
        public bool $canEdit,
        public bool $canEditSchedule,
    ) {}
}
