<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCharacteristic;
use InvalidArgumentException;

final class VenueFacilitiesSynchronizer
{
    /**
     * @param  array<string, mixed>  $characteristics
     * @param  array<int, int|string>  $amenityIds
     */
    public function sync(Venue $venue, array $characteristics, array $amenityIds): void
    {
        $normalizedAmenityIds = collect($amenityIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $amenities = Amenity::query()
            ->whereKey($normalizedAmenityIds->all())
            ->where('is_active', true)
            ->get();

        if ($amenities->count() !== $normalizedAmenityIds->count()) {
            throw new InvalidArgumentException('Одна из выбранных опций площадки недоступна.');
        }

        $group = $venue->type === VenueTypeEnum::STREET_COURT ? 'outdoor' : 'indoor';
        $hasInapplicableAmenity = $amenities->contains(
            fn (Amenity $amenity): bool => ! in_array((string) $amenity->applies_to, ['all', $group], true),
        );

        if ($hasInapplicableAmenity) {
            throw new InvalidArgumentException('Выбранная опция не подходит для этого типа площадки.');
        }

        $hoopsCount = isset($characteristics['hoops_count'])
            ? (int) $characteristics['hoops_count']
            : null;

        VenueCharacteristic::query()->updateOrCreate(
            ['venue_id' => $venue->id],
            [
                'hoops_count' => $hoopsCount,
                'hoops_condition' => isset($characteristics['hoops_condition'])
                    ? (int) $characteristics['hoops_condition']
                    : null,
                'surface_condition' => isset($characteristics['surface_condition'])
                    ? (int) $characteristics['surface_condition']
                    : null,
                'first_hoop_marking' => $characteristics['first_hoop_marking'] ?? null,
                'second_hoop_marking' => $hoopsCount === 2
                    ? ($characteristics['second_hoop_marking'] ?? null)
                    : null,
            ],
        );

        $venue->amenities()->sync($normalizedAmenityIds->all());
    }
}
