<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Validation\ValidationException;

/**
 * Court equipment depends on this court's indoor/outdoor environment,
 * never on the category of its parent venue.
 */
final class VenueCourtFacilitiesSynchronizer
{
    public function sync(VenueCourt $court, array $ids): void
    {
        $requested = collect($ids)->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();

        $amenities = Amenity::query()->whereKey($requested)
            ->where('is_active', true)->where('scope', 'court')->get();
        $environment = $court->environment?->value;

        if ($amenities->count() !== count($requested)
            || $amenities->contains(fn (Amenity $amenity): bool =>
                $environment === null
                || ! in_array((string) $amenity->applies_to, ['all', $environment], true)
            )) {
            throw ValidationException::withMessages([
                'amenity_ids' => 'Выберите только оснащение, доступное для типа этого зала или корта.',
            ]);
        }

        $court->amenities()->sync($requested);
    }
}
