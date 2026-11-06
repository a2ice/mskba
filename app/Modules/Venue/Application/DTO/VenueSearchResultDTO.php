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
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $courtAccess = [],
        /** @var array<string, mixed>|null */
        public ?array $matchedCourt = null,
    ) {}

    /**
     * Walk-in access, unlike merely free of payment, does not need booking or
     * an explicit approval. Callers should use hasFreeCourt for free pricing.
     */
    public function hasFreeAccess(): bool
    {
        foreach ($this->courtAccess['free'] ?? [] as $court) {
            if (! $court['requires_booking'] && ! $court['requires_booking_approval']) {
                return true;
            }
        }

        return false;
    }

    public function hasFreeCourt(): bool
    {
        return ($this->courtAccess['free'] ?? []) !== [];
    }

    public function hasPaidCourt(): bool
    {
        return ($this->courtAccess['paid'] ?? []) !== [];
    }

    public function hasApprovalCourt(): bool
    {
        return ($this->courtAccess['approval'] ?? []) !== [];
    }

    public function publicUrl(): string
    {
        return $this->matchedCourt['url'] ?? route('venues.show', $this->routeIdentifier());
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }
}
