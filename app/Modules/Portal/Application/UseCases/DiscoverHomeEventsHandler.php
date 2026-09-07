<?php

namespace App\Modules\Portal\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Location\Application\Services\AddressDisplayFormatter;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class DiscoverHomeEventsHandler
{
    public function __construct(
        private AddressDisplayFormatter $addressFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $filters): array
    {
        $timezone = (string) config('app.timezone', 'Europe/Moscow');
        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_from'], $timezone)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['date_to'], $timezone)->endOfDay();
        $type = (string) ($filters['type'] ?? 'any');
        $limit = max(1, min((int) ($filters['limit'] ?? 30), 50));

        $results = collect();

        if ($type !== 'tournament') {
            $results = $results->concat($this->eventResults($filters, $from, $to, $type));
        }

        if ($type === 'any' || $type === 'tournament') {
            $results = $results->concat($this->tournamentResults($filters, $from, $to, $type));
        }

        return $results
            ->sortBy('sort_at')
            ->take($limit)
            ->map(function (array $result): array {
                unset($result['sort_at']);

                return $result;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function eventResults(
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $type,
    ): Collection {
        $query = Event::query()
            ->with([
                'venue.location.address',
                'venue.location.metroStations',
                'primaryGame',
            ])
            ->where('status', EventStatusEnum::PUBLISHED->value)
            ->where('visibility', EventVisibilityEnum::PUBLIC->value)
            ->whereBetween('starts_at', [$from, $to]);

        if ($type !== 'any') {
            $eventType = EventTypeEnum::tryFrom($type);
            if ($eventType === null) {
                return collect();
            }
            $query->where('type', $eventType->value);
        }

        $this->applyVenueFilters($query, 'venue', $filters);

        if ($type === EventTypeEnum::GAME->value) {
            $format = (string) ($filters['format'] ?? 'any');
            $gameMode = (string) ($filters['game_mode'] ?? 'any');

            if ($format !== 'any') {
                $query->whereHas('primaryGame', fn (Builder $gameQuery) => $gameQuery->where('format', $format));
            }
            if ($gameMode !== 'any') {
                $query->whereHas('primaryGame', fn (Builder $gameQuery) => $gameQuery->where('recruitment_mode', $gameMode));
            }
        }

        $candidateLimit = $this->radiusFilter($filters) === null ? 100 : 500;

        return $query
            ->orderBy('starts_at')
            ->limit($candidateLimit)
            ->get()
            ->filter(fn (Event $event): bool => $this->venueWithinRadius($event->venue, $filters))
            ->map(fn (Event $event): array => $this->eventResult($event));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function tournamentResults(
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $type,
    ): Collection {
        $fromDate = $from->toDateString();
        $query = Tournament::query()
            ->with([
                'defaultVenue.location.address',
                'defaultVenue.location.metroStations',
            ])
            ->where('status', TournamentStatusEnum::CONFIRMED->value)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->where(function (Builder $periodQuery) use ($fromDate): void {
                $periodQuery
                    ->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $fromDate);
            })
            ->where(function (Builder $periodQuery) use ($fromDate): void {
                $periodQuery
                    ->whereNull('tournament_closed_at')
                    ->orWhereDate('tournament_closed_at', '>=', $fromDate);
            });

        $this->applyVenueFilters($query, 'defaultVenue', $filters);

        if ($type === 'tournament') {
            $format = (string) ($filters['format'] ?? 'any');
            $enrollment = (string) ($filters['enrollment'] ?? 'any');

            if ($format !== 'any') {
                $query->where('format', $format);
            }
            if ($enrollment !== 'any') {
                $query->where('enrollment_policy', $enrollment);
            }
        }

        $candidateLimit = $this->radiusFilter($filters) === null ? 100 : 500;

        return $query
            ->orderBy('starts_on')
            ->limit($candidateLimit)
            ->get()
            ->filter(fn (Tournament $tournament): bool => $this->venueWithinRadius($tournament->defaultVenue, $filters))
            ->map(fn (Tournament $tournament): array => $this->tournamentResult($tournament, $from));
    }

    /**
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyVenueFilters(Builder $query, string $venueRelation, array $filters): void
    {
        $venueId = isset($filters['venue_id']) ? (int) $filters['venue_id'] : null;
        $cityId = isset($filters['city_id']) ? (int) $filters['city_id'] : null;
        $districtId = isset($filters['district_id']) ? (int) $filters['district_id'] : null;
        $street = trim((string) ($filters['street'] ?? ''));
        $metroIds = collect($filters['metro_station_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($venueId !== null && $venueId > 0) {
            $query->whereHas($venueRelation, fn (Builder $venueQuery) => $venueQuery->whereKey($venueId));
        }

        if ($cityId !== null && $cityId > 0) {
            $query->whereHas(
                $venueRelation.'.location.address',
                fn (Builder $addressQuery) => $addressQuery->where('city_id', $cityId),
            );
        }

        if ($districtId !== null && $districtId > 0) {
            $query->whereHas(
                $venueRelation.'.location.address',
                fn (Builder $addressQuery) => $addressQuery->where('district_id', $districtId),
            );
        }

        if ($street !== '') {
            $query->whereHas(
                $venueRelation.'.location.address',
                fn (Builder $addressQuery) => $addressQuery->where('street', $street),
            );
        }

        if ($metroIds !== []) {
            $query->whereHas(
                $venueRelation.'.location.metroStations',
                fn (Builder $metroQuery) => $metroQuery->whereIn('metro_stations.id', $metroIds),
            );
        }

        $radius = $this->radiusFilter($filters);
        if ($radius !== null) {
            $latitudeDelta = $radius['radius_km'] / 111.32;
            $longitudeScale = max(0.1, cos(deg2rad($radius['latitude'])));
            $longitudeDelta = $radius['radius_km'] / (111.32 * $longitudeScale);

            $query->whereHas(
                $venueRelation.'.location.address',
                fn (Builder $addressQuery) => $addressQuery
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [
                        $radius['latitude'] - $latitudeDelta,
                        $radius['latitude'] + $latitudeDelta,
                    ])
                    ->whereBetween('longitude', [
                        $radius['longitude'] - $longitudeDelta,
                        $radius['longitude'] + $longitudeDelta,
                    ]),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{latitude: float, longitude: float, radius_km: float, radius_meters: float}|null
     */
    private function radiusFilter(array $filters): ?array
    {
        if (! isset($filters['latitude'], $filters['longitude'], $filters['radius_km'])) {
            return null;
        }

        $latitude = (float) $filters['latitude'];
        $longitude = (float) $filters['longitude'];
        $radiusKm = (float) $filters['radius_km'];

        if ($radiusKm <= 0) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => $radiusKm,
            'radius_meters' => $radiusKm * 1000,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function venueWithinRadius(?Venue $venue, array $filters): bool
    {
        $radius = $this->radiusFilter($filters);
        if ($radius === null) {
            return true;
        }

        $address = $venue?->location?->address;
        if ($address?->latitude === null || $address->longitude === null) {
            return false;
        }

        return $this->distanceMeters(
            $radius['latitude'],
            $radius['longitude'],
            (float) $address->latitude,
            (float) $address->longitude,
        ) <= $radius['radius_meters'];
    }

    private function distanceMeters(float $latA, float $lonA, float $latB, float $lonB): int
    {
        $earthRadius = 6_371_000;
        $latDelta = deg2rad($latB - $latA);
        $lonDelta = deg2rad($lonB - $lonA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lonDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** @return array<string, mixed> */
    private function eventResult(Event $event): array
    {
        $game = $event->primaryGame;

        return [
            'kind' => 'event',
            'id' => $event->id,
            'type' => $event->type->value,
            'type_label' => $event->type->label(),
            'title' => $event->title,
            'url' => route('events.show', $event->routeIdentifier()),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'format' => $game?->format?->value,
            'format_label' => $game?->format?->label(),
            'recruitment_mode' => $game?->recruitment_mode?->value,
            'recruitment_mode_label' => $game?->recruitment_mode?->label(),
            'venue' => $this->venueResult($event->venue),
            'sort_at' => $event->starts_at?->getTimestamp() ?? PHP_INT_MAX,
        ];
    }

    /** @return array<string, mixed> */
    private function tournamentResult(Tournament $tournament, CarbonImmutable $from): array
    {
        $startsOn = $tournament->starts_on;
        $sortDate = $startsOn?->isBefore($from) ? $from->startOfDay() : $startsOn?->startOfDay();

        return [
            'kind' => 'tournament',
            'id' => $tournament->id,
            'type' => 'tournament',
            'type_label' => 'Турнир',
            'title' => $tournament->title,
            'url' => route('tournaments.show', $tournament->routeIdentifier()),
            'starts_on' => $tournament->starts_on?->toDateString(),
            'ends_on' => $tournament->ends_on?->toDateString(),
            'format' => $tournament->format?->value,
            'format_label' => $tournament->format?->label(),
            'enrollment_policy' => $tournament->enrollment_policy?->value,
            'enrollment_policy_label' => $tournament->enrollment_policy?->label(),
            'venue' => $this->venueResult($tournament->defaultVenue),
            'sort_at' => $sortDate?->getTimestamp() ?? PHP_INT_MAX,
        ];
    }

    /** @return array<string, mixed>|null */
    private function venueResult(?Venue $venue): ?array
    {
        if ($venue === null) {
            return null;
        }

        $address = $venue->location?->address;

        return [
            'id' => $venue->id,
            'name' => $venue->name,
            'address' => $this->addressFormatter->format(
                $venue->raw_address,
                $address?->city,
                $address?->street,
                $address?->building,
            ),
        ];
    }
}
