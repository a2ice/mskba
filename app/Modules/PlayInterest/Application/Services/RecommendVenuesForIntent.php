<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

final class RecommendVenuesForIntent
{
    /**
     * Spatial/amenity discovery, NOT booking availability. Event- and court-aware
     * slots must be checked separately before displaying a free/occupied badge.
     *
     * @return list<array<string, mixed>>
     */
    public function handle(PlayIntent $intent, int $limit = 20): array
    {
        if ($intent->status !== 'active' || $intent->expires_at?->isPast()) {
            return [];
        }

        $isSimilar = (bool) $intent->include_similar_venues;
        $latitude = $intent->center_latitude;
        $longitude = $intent->center_longitude;
        $radius = (int) ($intent->radius_km ?? 10);
        $reqs = collect($intent->amenity_preferences ?? []);
        $requiredIds = $reqs->where('priority', 'required')->pluck('amenity_id')->map(fn ($n): int => (int) $n)->all();
        $preferredIds = $reqs->where('priority', 'preferred')->pluck('amenity_id')->map(fn ($n): int => (int) $n)->all();

        $query = Venue::query()
            ->where('status', VenueStatusEnum::CONFIRMED)
            ->where('operational_status', VenueOperationalStatusEnum::ACTIVE)
            ->with(['location.address', 'amenities:id,name,alias,is_active', 'primaryCourt'])
            ->orderBy('id');

        if (! $isSimilar) {
            $query->whereKey($intent->venue_id);
        } elseif ($latitude !== null && $longitude !== null) {
            // DB bounding box narrows work before exact Haversine scoring.
            $latRadius = $radius / 111.0;
            $lonRadius = $radius / max(1.0, 111.0 * cos(deg2rad((float) $latitude)));
            $query->whereHas('location.address', function (Builder $q) use ($latitude, $longitude, $latRadius, $lonRadius): void {
                $q->whereBetween('latitude', [$latitude - $latRadius, $latitude + $latRadius])
                    ->whereBetween('longitude', [$longitude - $lonRadius, $longitude + $lonRadius]);
            });
        }

        $query->when($requiredIds !== [], function (Builder $q) use ($requiredIds): void {
            foreach ($requiredIds as $id) {
                $q->whereHas('amenities', fn (Builder $a) => $a->where('amenities.id', $id)->where('is_active', true));
            }
        });

        $limit = max(1, min($limit, 50));
        $matches = [];

        // Iterator avoids loading the whole venue catalog into application memory.
        foreach ($query->lazy(100) as $venue) {
            /** @var Venue $venue */
            $lat = $venue->location?->address?->latitude;
            $lon = $venue->location?->address?->longitude;
            $distance = null;

            if ($latitude !== null && $longitude !== null) {
                if ($lat === null || $lon === null) {
                    if ($isSimilar) {
                        continue;
                    }
                } else {
                    $distance = $this->haversineMeters(
                        (float) $latitude, (float) $longitude, (float) $lat, (float) $lon,
                    );
                    if ($isSimilar && $distance > $radius * 1000) {
                        continue;
                    }
                }
            }

            if ($intent->access_mode === 'free' && ! $venue->hasFreeAccess()) {
                continue;
            }
            if ($intent->access_mode === 'paid' && $venue->requires_payment !== true) {
                continue;
            }

            $amenityIds = $venue->amenities->where('is_active', true)->pluck('id')->map(fn ($n): int => (int) $n)->all();
            $preferredHits = count(array_intersect($preferredIds, $amenityIds));
            $softScore = $preferredIds === [] ? 100 : 100 * $preferredHits / count($preferredIds);
            $distanceScore = $distance === null ? 100 : max(0, 100 * (1 - $distance / max(1, $radius * 1000)));
            $score = (int) round($distanceScore * .7 + $softScore * .3);

            $matches[] = [
                'venue_id' => (int) $venue->id,
                'name' => $venue->name,
                'url' => route('venues.show', $venue->routeIdentifier()),
                'distance_meters' => $distance,
                'score' => $score, // geographic + amenity relevance, NOT likelihood of a game
                'preferred_amenities_matched' => $preferredHits,
                'preferred_amenities_total' => count($preferredIds),
                'booking_availability' => 'not_checked',
                'availability_note' => 'Нужно проверить свободные часы и зал для конкретной даты.',
            ];
        }

        usort($matches, fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: (($a['distance_meters'] ?? 0) <=> ($b['distance_meters'] ?? 0))
            ?: ($a['venue_id'] <=> $b['venue_id']));

        return array_slice($matches, 0, $limit);
    }

    private function haversineMeters(float $la, float $lo, float $lb, float $lob): int
    {
        $angle = sin(deg2rad($lb - $la) / 2) ** 2
            + cos(deg2rad($la)) * cos(deg2rad($lb))
            * sin(deg2rad($lob - $lo) / 2) ** 2;

        return (int) round(6371000 * 2 * atan2(sqrt($angle), sqrt(max(0, 1 - $angle))));
    }
}
