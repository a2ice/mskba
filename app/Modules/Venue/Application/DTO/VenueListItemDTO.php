<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueListItemDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $status,
        public string $type,
        public ?string $description,
        public ?string $rawAddress,
        public bool $canView,
        public bool $canEdit,
        public bool $canEditSchedule,
    ) {}
}
