<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Venue\Application\DTO\VenueSearchResultDTO;
use App\Modules\Venue\Application\Services\VenueSearchCache;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class SearchVenuesHandler
{
    public function __construct(
        private ListVenuesHandler $listVenues,
        private VenueSearchCache $cache,
        private VenueEventAvailability $availability,
        private AddressDisplayFormatter $addressFormatter,
    ) {}

    /**
     * @return array<int, VenueSearchResultDTO>
     */
    public function handle(
        ?User $user,
        ?Actor $actor,
        ?string $query = null,
        ?int $venueId = null,
        ?VenueTypeEnum $type = null,
        ?VenueStatusEnum $status = null,
        ?int $metroStationId = null,
        ?bool $requiresPayment = null,
        ?bool $requiresBookingApproval = null,
        bool $confirmedOnly = false,
        ?VenueOperationalStatusEnum $operationalStatus = null,
        ?CarbonImmutable $startsAt = null,
        ?int $durationMinutes = null,
        int $limit = 20,
    ): array {
        $parameters = [
            'query' => mb_strtolower(trim((string) $query)),
            'venue_id' => $venueId,
            'type' => $type?->value,
            'status' => $status?->value,
            'metro_station_id' => $metroStationId,
            'requires_payment' => $requiresPayment,
            'requires_booking_approval' => $requiresBookingApproval,
            'confirmed_only' => $confirmedOnly,
            'operational_status' => $operationalStatus?->value,
            'starts_at' => $startsAt?->toIso8601String(),
            'duration_minutes' => $durationMinutes,
            'limit' => max(1, min($limit, 200)),
        ];

        $resolve = fn (): array => $this->resolve($user, $actor, $parameters);
        $documents = $confirmedOnly
            ? $this->cache->rememberResult($parameters, $resolve)
            : $resolve();

        return array_map(
            fn (array $venue): VenueSearchResultDTO => new VenueSearchResultDTO(
                id: $venue['id'],
                name: $venue['name'],
                alias: $venue['alias'],
                type: $venue['type'],
                status: $venue['status'],
                statusSlug: $venue['status_slug'],
                operationalStatus: $venue['operational_status'],
                requiresPayment: $venue['requires_payment'],
                requiresBookingApproval: $venue['requires_booking_approval'],
                shortDescription: $venue['short_description'],
                rawAddress: $venue['raw_address'],
                displayAddress: $this->addressFormatter->format($venue['raw_address']),
                latitude: $venue['latitude'],
                longitude: $venue['longitude'],
                metroStations: $venue['metro_stations'],
                tags: $venue['tags'],
            ),
            $documents,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function resolve(?User $user, ?Actor $actor, array $parameters): array
    {
        $venues = collect($this->cache->documents());

        if ($parameters['confirmed_only']) {
            $venues = $venues->where('status_slug', VenueStatusEnum::CONFIRMED->value);
        } else {
            $visibleIds = collect($this->listVenues->handle($user, $actor))->pluck('id')->all();
            $venues = $venues->whereIn('id', $visibleIds);
        }

        $venues = $venues
            ->when($parameters['venue_id'], fn (Collection $items, int $value) => $items->where('id', $value))
            ->when($parameters['type'], fn (Collection $items, string $value) => $items->where('type_slug', $value))
            ->when($parameters['status'], fn (Collection $items, string $value) => $items->where('status_slug', $value))
            ->when($parameters['operational_status'], fn (Collection $items, string $value) => $items->where('operational_status', $value))
            ->when($parameters['metro_station_id'], fn (Collection $items, int $value) => $items->filter(
                fn (array $venue): bool => in_array($value, $venue['metro_station_ids'], true),
            ))
            ->when($parameters['requires_payment'] !== null, fn (Collection $items) => $items->where(
                'requires_payment',
                $parameters['requires_payment'],
            ))
            ->when($parameters['requires_booking_approval'] !== null, fn (Collection $items) => $items->where(
                'requires_booking_approval',
                $parameters['requires_booking_approval'],
            ))
            ->when($parameters['query'] !== '', fn (Collection $items) => $items->filter(
                fn (array $venue): bool => str_contains($venue['search_text'], $parameters['query']),
            ));

        if ($parameters['starts_at'] !== null && $parameters['duration_minutes'] !== null) {
            $startsAt = CarbonImmutable::parse($parameters['starts_at']);
            $endsAt = $startsAt->addMinutes($parameters['duration_minutes']);
            $models = Venue::query()
                ->with(['schedule.intervals', 'schedule.exceptions.intervals'])
                ->whereIn('id', $venues->pluck('id')->all())
                ->get()
                ->keyBy('id');
            $occupiedVenueIds = VenueBooking::query()
                ->whereIn('venue_id', $models->keys()->all())
                ->whereIn('status', [
                    VenueBookingStatusEnum::PENDING->value,
                    VenueBookingStatusEnum::CONFIRMED->value,
                ])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->pluck('venue_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $venues = $venues->filter(function (array $document) use ($models, $occupiedVenueIds, $startsAt, $endsAt): bool {
                $venue = $models->get($document['id']);

                if ($venue === null || in_array($document['id'], $occupiedVenueIds, true)) {
                    return false;
                }

                try {
                    $this->availability->assertAvailable(
                        $venue,
                        $startsAt,
                        $endsAt,
                        checkBookings: false,
                    );

                    return true;
                } catch (InvalidArgumentException) {
                    return false;
                }
            });
        }

        return $venues
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->take($parameters['limit'])
            ->values()
            ->all();
    }
}
