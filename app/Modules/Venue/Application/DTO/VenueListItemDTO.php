<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueListItemDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $status,
        public string $statusSlug,
        public string $type,
        public string $typeSlug,
        public string $operationalStatus,
        public string $operationalStatusSlug,
        public bool $requiresPayment,
        public bool $requiresBookingApproval,
        public ?string $shortDescription,
        public ?string $rawAddress,
        public ?string $imageUrl,
        public ?float $latitude,
        public ?float $longitude,
        public bool $canView,
        public bool $canEdit,
        public bool $canEditSchedule,
        public bool $canRemove,
    ) {}

    public function hasFreeAccess(): bool
    {
        return ! $this->requiresPayment && ! $this->requiresBookingApproval;
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }
}
