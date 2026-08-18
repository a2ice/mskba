<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class VenueProximityService
{
    public function strongRadiusMeters(): int
    {
        return max(1, (int) config('integrations.venue_duplicates.strong_radius_meters', 50));
    }

    public function candidateRadiusMeters(): int
    {
        return max($this->strongRadiusMeters(), (int) config('integrations.venue_duplicates.candidate_radius_meters', 200));
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     */
    public function existsNearCoordinates(
        VenueTypeEnum $type,
        float $latitude,
        float $longitude,
        int $radiusMeters,
        array $statuses = [],
        ?Actor $actor = null,
        ?int $exceptVenueId = null,
    ): bool {
        return $this->query($type, $statuses, $actor, $exceptVenueId)
            ->get()
            ->contains(fn (Venue $venue): bool => ($this->distanceToCoordinates($venue, $latitude, $longitude) ?? INF) <= $radiusMeters);
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     * @return array{venue: Venue, distance_meters: int}|null
     */
    public function nearestToCoordinates(
        VenueTypeEnum $type,
        float $latitude,
        float $longitude,
        int $radiusMeters,
        array $statuses = [],
        ?int $exceptVenueId = null,
    ): ?array {
        return $this->query($type, $statuses, null, $exceptVenueId)
            ->get()
            ->map(function (Venue $venue) use ($latitude, $longitude): ?array {
                $distance = $this->distanceToCoordinates($venue, $latitude, $longitude);

                return $distance === null ? null : [
                    'venue' => $venue,
                    'distance_meters' => $distance,
                ];
            })
            ->filter()
            ->filter(fn (array $candidate): bool => $candidate['distance_meters'] <= $radiusMeters)
            ->sortBy('distance_meters')
            ->first();
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     * @return Collection<int, Venue>
     */
    public function venuesNearVenue(Venue $venue, int $radiusMeters, array $statuses = []): Collection
    {
        $venue->loadMissing('location.address');
        $coordinates = $this->coordinates($venue);

        if ($coordinates === null) {
            return collect();
        }

        return $this->query($venue->type, $statuses, null, (int) $venue->id)
            ->get()
            ->filter(fn (Venue $candidate): bool => ($this->distanceBetween($venue, $candidate) ?? INF) <= $radiusMeters)
            ->values();
    }

    public function distanceBetween(Venue $first, Venue $second): ?int
    {
        $first->loadMissing('location.address');
        $second->loadMissing('location.address');
        $firstCoordinates = $this->coordinates($first);
        $secondCoordinates = $this->coordinates($second);

        if ($firstCoordinates === null || $secondCoordinates === null) {
            return null;
        }

        return $this->haversineMeters(...$firstCoordinates, ...$secondCoordinates);
    }

    private function distanceToCoordinates(Venue $venue, float $latitude, float $longitude): ?int
    {
        $venue->loadMissing('location.address');
        $coordinates = $this->coordinates($venue);

        return $coordinates === null
            ? null
            : $this->haversineMeters($coordinates[0], $coordinates[1], $latitude, $longitude);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function coordinates(Venue $venue): ?array
    {
        $address = $venue->location?->address;

        if ($address?->latitude === null || $address->longitude === null) {
            return null;
        }

        return [(float) $address->latitude, (float) $address->longitude];
    }

    private function haversineMeters(float $latA, float $lonA, float $latB, float $lonB): int
    {
        $earthRadius = 6_371_000;
        $latDelta = deg2rad($latB - $latA);
        $lonDelta = deg2rad($lonB - $lonA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lonDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * @param  array<int, VenueStatusEnum>  $statuses
     * @return Builder<Venue>
     */
    private function query(
        VenueTypeEnum $type,
        array $statuses,
        ?Actor $actor,
        ?int $exceptVenueId,
    ): Builder {
        return Venue::query()
            ->with('location.address')
            ->where('type', $type)
            ->whereHas('location.address', fn (Builder $query) => $query
                ->whereNotNull('latitude')
                ->whereNotNull('longitude'))
            ->when($exceptVenueId !== null, fn (Builder $query) => $query->whereKeyNot($exceptVenueId))
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn(
                'status',
                array_map(fn (VenueStatusEnum $status): string => $status->value, $statuses),
            ))
            ->when($actor !== null, fn (Builder $query) => $query->whereHas(
                'creatorActor',
                function (Builder $query) use ($actor): void {
                    $identityIds = $actor->user?->canonical()->identityIds() ?? [];
                    if ($identityIds !== []) {
                        $query->whereIn('user_id', $identityIds);
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                },
            ));
    }
}
