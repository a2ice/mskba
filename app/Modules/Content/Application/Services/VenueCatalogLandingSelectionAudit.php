<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\Gate;

final readonly class VenueCatalogLandingSelectionAudit
{
    public function __construct(private ListVenuesHandler $catalog) {}

    /**
     * An editorial guideline, not an indexing or activation rule.
     * Counts public venues, not individual courts or available slots.
     *
     * @return array{matching:int, city_matching:?int, missing_district:?int, district_count:?int}
     */
    public function inspect(VenueCatalogLanding $landing): array
    {
        Gate::authorize('manage-content');
        $criteria = $landing->criteria();
        $matches = $this->catalog->handle(null, null, $criteria, configuredRentalOnly: (bool) ($criteria['rental_configured'] ?? false));
        $result = [
            'matching' => count($matches),
            'city_matching' => null,
            'missing_district' => null,
            'district_count' => null,
        ];

        if (! isset($criteria['city_id'])) {
            return $result;
        }

        $cityId = $criteria['city_id'];
        if (isset($criteria['district_id'])) {
            unset($criteria['district_id']);
            $matches = $this->catalog->handle(null, null, $criteria, configuredRentalOnly: (bool) ($criteria['rental_configured'] ?? false));
        }

        $result['city_matching'] = count($matches);
        $result['district_count'] = District::query()->where('city_id', $cityId)->count();
        $result['missing_district'] = 0;
        foreach (array_chunk(array_column($matches, 'id'), 500) as $ids) {
            $result['missing_district'] += Venue::query()->whereIn('id', $ids)
                ->whereDoesntHave('location.address.district', fn ($district) => $district->where('city_id', $cityId))
                ->count();
        }

        return $result;
    }
}
