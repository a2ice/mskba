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
    public function handle(int $venueId, float $latitude, float $longitude, int $limit = 6): array
    {
        $limit = max(3, min($limit, 12));

        return $this->cache->rememberResult([
            'mode' => 'nearby-public-venues',
            'venue_id' => $venueId,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'limit' => $limit,
        ], function () use ($venueId, $latitude, $longitude, $limit): array {
            return collect($this->cache->documents())
                ->filter(static fn (array $venue): bool => (int) $venue['id'] !== $venueId
                    && ($venue['status_slug'] ?? null) === VenueStatusEnum::CONFIRMED->value
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
                    ];
                })
                ->sortBy('distance_meters')
                ->take($limit)
                ->values()
                ->all();
        });
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
