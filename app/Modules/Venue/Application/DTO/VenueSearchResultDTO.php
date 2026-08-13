<?php

namespace App\Modules\Venue\Application\DTO;

final readonly class VenueSearchResultDTO
{
    /**
     * @param  array<int, string>  $metroStations
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $alias,
        public string $type,
        public string $status,
        public string $statusSlug,
        public string $operationalStatus,
        public ?bool $requiresPayment,
        public bool $requiresBookingApproval,
        public ?string $shortDescription,
        public ?string $rawAddress,
        public ?string $displayAddress,
        public ?float $latitude,
        public ?float $longitude,
        public array $metroStations,
        public array $tags,
    ) {}

    public function hasFreeAccess(): bool
    {
        return $this->requiresPayment === false && ! $this->requiresBookingApproval;
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }
}
