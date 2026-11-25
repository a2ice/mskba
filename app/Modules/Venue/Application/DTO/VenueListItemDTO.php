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
        public ?bool $requiresPayment,
        public bool $requiresBookingApproval,
        public ?string $shortDescription,
        public ?string $rawAddress,
        public ?string $displayAddress,
        public ?string $imageUrl,
        public ?float $latitude,
        public ?float $longitude,
        public bool $canView,
        public bool $canEdit,
        public bool $canEditSchedule,
        public bool $canRemove,
        public bool $canDecideBookingRequests = false,
        public int $actionableBookingRequestsCount = 0,
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $courtAccessSummary = [],
        public ?string $matchedCourtUrl = null,
        public ?string $matchedCourtName = null,
        public ?bool $matchedRequiresBooking = null,
        public ?bool $matchedRequiresApproval = null,
    ) {}

    /**
     * Use the actual destination court (including similar-nearby cards) or
     * all active courts on a venue card. Never fall back to the parent price.
     *
     * @return list<string>
     */
    public function paymentBadgeStates(?string $destinationCourtUrl = null): array
    {
        $courtUrl = $destinationCourtUrl ?? $this->matchedCourtUrl;
        $states = [];
        foreach (['free', 'paid', 'unknown'] as $state) {
            $courts = $this->courtAccessSummary[$state] ?? [];
            if ($courtUrl !== null) {
                foreach ($courts as $court) {
                    if ($court['url'] === $courtUrl) {
                        return [$state];
                    }
                }
            } elseif ($courts !== []) {
                $states[] = $state;
            }
        }

        return $courtUrl !== null ? ['unknown'] : $states;
    }

    public function hasFreeAccess(): bool
    {
        return $this->requiresPayment === false && ! $this->requiresBookingApproval;
    }

    public function routeIdentifier(): string
    {
        return $this->id.'-'.$this->alias;
    }
}
