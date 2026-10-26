<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
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
        $excludedIds = array_map('intval', $intent->excluded_venue_ids ?? []);
        $criteriaOnly = $intent->venue_id === null;
        $searchCatalog = $criteriaOnly || $isSimilar;
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
            ->when($excludedIds !== [], fn (Builder $q) => $q->whereNotIn('id', $excludedIds))
            ->orderBy('id');

        // The venue chosen by "Хочу здесь играть" remains a candidate.
        // Type preference applies to *alternatives* only, and only when
        // the user opted into searching for similar venues.
        if ($searchCatalog && in_array($intent->venue_kind, ['outdoor', 'indoor'], true)) {
            $outdoor = $intent->venue_kind === 'outdoor';
            $query->where(function (Builder $q) use ($outdoor, $intent): void {
                if ($outdoor) {
                    $q->where('type', VenueTypeEnum::STREET_COURT);
                } else {
                    $q->where('type', '!=', VenueTypeEnum::STREET_COURT);
                }
                if ($intent->venue_id !== null) {
                    $q->orWhereKey($intent->venue_id);
                }
            });
        }

        if (! $searchCatalog) {
            $query->whereKey($intent->venue_id);
        } elseif ($isSimilar && $latitude !== null && $longitude !== null) {
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
            if (! $this->matchesVenue($intent, $venue)) {
                continue;
            }
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

    /**
     * Shared eligibility predicate for recommendations and potential venue
     * subscribers. Direct subscriptions are classified by the audience service.
     */
    public function matchesVenue(PlayIntent $intent, Venue $venue): bool
    {
        if ($intent->status !== 'active' || $intent->expires_at?->isPast()
            || in_array((int) $venue->id, array_map('intval', $intent->excluded_venue_ids ?? []), true)
            || $venue->status !== VenueStatusEnum::CONFIRMED
            || $venue->operational_status !== VenueOperationalStatusEnum::ACTIVE) {
            return false;
        }
        $direct = $intent->venue_id !== null && (int) $intent->venue_id === (int) $venue->id;
        if (! $direct && $intent->venue_id !== null && ! $intent->include_similar_venues) {
            return false;
        }
        if (! $direct && in_array($intent->venue_kind, ['indoor', 'outdoor'], true)) {
            $outdoor = $venue->type === VenueTypeEnum::STREET_COURT;
            if ($outdoor !== ($intent->venue_kind === 'outdoor')) {
                return false;
            }
        }
        if (! $direct && $intent->include_similar_venues) {
            $venue->loadMissing('location.address');
            $address = $venue->location?->address;
            if ($intent->center_latitude === null || $intent->center_longitude === null
                || $address?->latitude === null || $address?->longitude === null) {
                return false;
            }
            if ($this->haversineMeters(
                (float) $intent->center_latitude, (float) $intent->center_longitude,
                (float) $address->latitude, (float) $address->longitude,
            ) > (int) ($intent->radius_km ?? 10) * 1000) {
                return false;
            }
        }
        if ($intent->access_mode === 'free' && ! $venue->hasFreeAccess()) {
            return false;
        }
        if ($intent->access_mode === 'paid' && $venue->requires_payment !== true) {
            return false;
        }
        $required = collect($intent->amenity_preferences ?? [])
            ->where('priority', 'required')->pluck('amenity_id')->map(fn ($id): int => (int) $id)->all();
        if ($required !== []) {
            $venue->loadMissing('amenities');
            $available = $venue->amenities->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (array_diff($required, $available) !== []) {
                return false;
            }
        }
        return true;
    }

    private function haversineMeters(float $la, float $lo, float $lb, float $lob): int
    {
        $angle = sin(deg2rad($lb - $la) / 2) ** 2
            + cos(deg2rad($la)) * cos(deg2rad($lb))
            * sin(deg2rad($lob - $lo) / 2) ** 2;

        return (int) round(6371000 * 2 * atan2(sqrt($angle), sqrt(max(0, 1 - $angle))));
    }
}
