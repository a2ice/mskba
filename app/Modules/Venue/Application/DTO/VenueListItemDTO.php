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
        /** @var list<VenueMetroStationDTO> */
        public array $metroStations = [],
        public ?float $rating = null,
    ) {}

    /**
     * Use the actual destination court (including similar-nearby cards) or
     * all non-deleted courts on a venue card. Never fall back to the parent price.
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

    /** The destination metadata also works for nearby cards with an explicit URL. */
    public function cardCourt(?string $url = null): ?array
    {
        $url ??= $this->matchedCourtUrl;
        if ($url === null) {
            return null;
        }

        foreach (['free', 'paid', 'unknown'] as $state) {
            foreach ($this->courtAccessSummary[$state] ?? [] as $court) {
                if ($court['url'] === $url) {
                    return $court;
                }
            }
        }

        return null;
    }

    public function courtCount(): int
    {
        return count($this->courtAccessSummary['free'] ?? [])
            + count($this->courtAccessSummary['paid'] ?? [])
            + count($this->courtAccessSummary['unknown'] ?? []);
    }

    public function cardCourtCount(?string $url = null): int
    {
        return $this->cardCourt($url) === null ? $this->courtCount() : 0;
    }

    public function cardPaymentCount(string $state): int
    {
        return $this->matchedCourtUrl !== null
            ? (int) in_array($state, $this->paymentBadgeStates(), true)
            : count($this->courtAccessSummary[$state] ?? []);
    }

    /** @return list<string> Effective operational states of the actual destination or all courts. */
    public function cardOperationalStates(?array $court = null): array
    {
        $court ??= $this->cardCourt();
        $courts = $court !== null ? [$court] : array_merge(
            $this->courtAccessSummary['free'] ?? [],
            $this->courtAccessSummary['paid'] ?? [],
            $this->courtAccessSummary['unknown'] ?? [],
        );

        $states = [];
        foreach ($courts as $item) {
            $states[] = $item['operational_status'] ?? $this->operationalStatusSlug;
        }

        return array_values(array_intersect(
            ['active', 'temporarily_closed'],
            $states !== [] ? $states : [$this->operationalStatusSlug],
        ));
    }

    /** Truncate at the closest complete word around the preferred 50 characters. */
    public static function catalogDescription(?string $description): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $description)) ?? '');
        if (mb_strlen($text) <= 50) {
            return $text;
        }

        $best = '';
        $prefix = '';
        foreach (explode(' ', $text) as $word) {
            $prefix = $prefix === '' ? $word : $prefix.' '.$word;
            $candidate = preg_replace('/[\s\p{P}]+$/u', '', $prefix) ?? '';
            if (mb_strlen($candidate) > 58) {
                break;
            }
            if ($candidate !== '' && ($best === '' || abs(mb_strlen($candidate) - 50) < abs(mb_strlen($best) - 50))) {
                $best = $candidate;
            }
        }

        // A single long word must never be cut in half.
        if ($best === '') {
            $best = preg_replace('/[\s\p{P}]+$/u', '', explode(' ', $text)[0]) ?? '';
        }

        return $best === '' ? '' : $best.'…';
    }

    /** @return array{approval: bool, booking: bool} */
    public function cardConditions(?array $court = null): array
    {
        $courts = $court !== null ? [$court] : array_merge(
            $this->courtAccessSummary['free'] ?? [],
            $this->courtAccessSummary['paid'] ?? [],
            $this->courtAccessSummary['unknown'] ?? [],
        );

        return [
            'approval' => collect($courts)->contains(fn (array $item): bool => (bool) ($item['requires_booking_approval'] ?? false)),
            'booking' => collect($courts)->contains(fn (array $item): bool => (bool) ($item['requires_booking'] ?? false)),
        ];
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
