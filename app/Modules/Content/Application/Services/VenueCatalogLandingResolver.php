<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\VenueCatalogLanding;

/** Not connected to public routes until the catalogue integration is accepted. */
final class VenueCatalogLandingResolver
{
    public function forSlug(string $slug): ?VenueCatalogLanding
    {
        return VenueCatalogLanding::query()->where('is_active', true)->where('slug', $slug)->first();
    }

    public function forCatalog(array $validatedFilters): ?VenueCatalogLanding
    {
        $criteria = VenueCatalogLandingCriteria::fromCatalog($validatedFilters);
        if ($criteria === null || $criteria === []) {
            return null;
        }

        $landing = VenueCatalogLanding::query()->where('is_active', true)
            ->where('active_filter_signature', VenueCatalogLandingCriteria::signature($criteria))->first();

        // Guard against stale signatures and ensure exact semantic equality.
        return $landing !== null && $landing->criteria() === $criteria ? $landing : null;
    }
}
