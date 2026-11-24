<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Venue\Application\Services\VenueSearchCache;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;

final readonly class FindNearbyVenuesHandler
{
    public function __construct(private VenueSearchCache $cache) {}

    /**
     * @return array<int, array{id: int, name: string, latitude: float, longitude: float, distance_meters: int, preview_url: string}>
     */
    public function handle(int $venueId, float $latitude, float $longitude, int $limit = 6, ?int $radiusKm = null): array
    {
        $limit = max(1, min($limit, 50));
        $radiusKm = $radiusKm === null ? null : max(1, min($radiusKm, 10));

        return $this->cache->rememberResult([
            'mode' => 'nearby-public-venues',
            'venue_id' => $venueId,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'limit' => $limit,
            'radius_km' => $radiusKm,
        ], function () use ($venueId, $latitude, $longitude, $limit, $radiusKm): array {
            return collect($this->cache->documents())
                ->filter(static fn (array $venue): bool => (int) $venue['id'] !== $venueId
                    && ($venue['status_slug'] ?? null) === VenueStatusEnum::CONFIRMED->value
                    && ($venue['is_published'] ?? false)
                    && is_numeric($venue['latitude'] ?? null)
                    && is_numeric($venue['longitude'] ?? null))
                ->map(function (array $venue) use ($latitude, $longitude): array {
                    $venueLatitude = (float) $venue['latitude'];
                    $venueLongitude = (float) $venue['longitude'];

                    return [
                        'id' => (int) $venue['id'],
                        'name' => (string) $venue['name'],
                        'latitude' => $venueLatitude,
                        'longitude' => $venueLongitude,
                        'distance_meters' => $this->haversineMeters(
                            $latitude,
                            $longitude,
                            $venueLatitude,
                            $venueLongitude,
                        ),
                        'preview_url' => route('venues.preview', $venue['id'].'-'.$venue['alias']),
                        'url' => route('venues.show', $venue['id'].'-'.$venue['alias']),
                    ];
                })
                ->filter(fn (array $venue): bool => $radiusKm === null || $venue['distance_meters'] <= $radiusKm * 1000)
                ->sortBy('distance_meters')
                ->take($limit)
                ->values()
                ->all();
        });
    }

    /**
     * Match every active criterion against one actual court. A venue with a
     * free outdoor court and a paid indoor hall must never combine those two
     * courts into one fictional result.
     *
     * @return array<int, array<string, mixed>>
     */
    public function similar(int $venueId, float $latitude, float $longitude, ?int $courtId = null, array $criteria = ['type', 'environment', 'payment']): array
    {
        $allowed = ['type', 'environment', 'payment', 'requires_booking',
            'requires_booking_approval', 'surface', 'amenities', 'dimensions', 'hoops'];
        $criteria = array_values(array_intersect($allowed, $criteria));
        $documents = collect($this->cache->documents())->keyBy('id');
        $source = $documents->get($venueId);
        if ($source === null) {
            return [];
        }

        $zones = static function (array $document): array {
            $result = [];
            foreach (['free', 'paid', 'unknown'] as $payment) {
                foreach ($document['court_access'][$payment] ?? [] as $zone) {
                    $features = $document['court_features'][(int) $zone['court_id']] ?? [];
                    $result[] = [
                        ...$zone,
                        'payment' => $payment,
                        'environment' => $document['court_environments'][(int) $zone['court_id']] ?? null,
                        'surface' => $features['surface'] ?? null,
                        'amenities' => $features['amenities'] ?? null,
                        'dimensions' => $features['dimensions'] ?? null,
                        'hoops' => $features['hoops'] ?? null,
                    ];
                }
            }

            return $result;
        };

        $sources = array_values(array_filter($zones($source),
            static fn (array $zone): bool => $courtId === null || (int) $zone['court_id'] === $courtId));
        if ($sources === []) {
            return [];
        }

        $matches = [];
        foreach ($documents as $candidate) {
            if ((int) $candidate['id'] === $venueId || ! ($candidate['is_published'] ?? false)
                || ($candidate['status_slug'] ?? null) !== VenueStatusEnum::CONFIRMED->value
                || ! is_numeric($candidate['latitude'] ?? null) || ! is_numeric($candidate['longitude'] ?? null)
                || (in_array('type', $criteria, true) && ($candidate['type_slug'] ?? null) !== ($source['type_slug'] ?? null))) {
                continue;
            }

            foreach ($zones($candidate) as $zone) {
                foreach ($sources as $origin) {
                    if (! $this->zonesMatch($origin, $zone, $criteria)) {
                        continue;
                    }

                    $matches[] = [
                        'id' => (int) $candidate['id'],
                        'name' => (string) $candidate['name'],
                        'court_name' => (string) $zone['name'],
                        'court_id' => (int) $zone['court_id'],
                        'url' => $zone['url'],
                        'distance_meters' => $this->haversineMeters(
                            $latitude, $longitude, (float) $candidate['latitude'], (float) $candidate['longitude']
                        ),
                    ];
                    // One card per venue, linked to the matching court.
                    continue 3;
                }
            }
        }

        $sorted = collect($matches)->sortBy('distance_meters')->values();
        $withinRadius = $sorted->where('distance_meters', '<=', 10_000);

        return ($withinRadius->isEmpty() ? $sorted->take(3) : $withinRadius->take(6))
            ->values()->all();
    }

    /** @param array<int, string> $criteria */
    private function zonesMatch(array $source, array $candidate, array $criteria): bool
    {
        foreach (['environment', 'payment', 'requires_booking', 'requires_booking_approval', 'surface', 'hoops'] as $key) {
            if (! in_array($key, $criteria, true)) {
                continue;
            }
            if (($source[$key] ?? null) === null || ($source[$key] ?? null) === 'unknown'
                || ($source[$key] ?? null) !== ($candidate[$key] ?? null)) {
                return false;
            }
        }

        if (in_array('amenities', $criteria, true)) {
            $sourceAmenities = $source['amenities'] ?? null;
            $candidateAmenities = $candidate['amenities'] ?? null;
            if (! is_array($sourceAmenities) || ! is_array($candidateAmenities)
                || array_diff($sourceAmenities, $candidateAmenities) !== []) {
                return false;
            }
        }

        if (in_array('dimensions', $criteria, true)) {
            $first = $source['dimensions'] ?? null;
            $second = $candidate['dimensions'] ?? null;
            if (! is_array($first) || ! is_array($second)
                || count($first) !== 2 || count($second) !== 2) {
                return false;
            }
            // Court sizes within 5% are considered similar; incomplete data
            // cannot match by guessing a standard court size.
            foreach ([0, 1] as $axis) {
                if ((float) $first[$axis] <= 0 || (float) $second[$axis] <= 0
                    || abs((float) $first[$axis] - (float) $second[$axis])
                        > max(1.0, (float) $first[$axis] * 0.05)) {
                    return false;
                }
            }
        }

        return true;
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
}
