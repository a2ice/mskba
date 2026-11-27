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
     * @return array{matching:int, city_matching:?int, missing_district:?int, district_count:?int, districts:list<array{id:int,name:string,matching:int}>}
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
            'districts' => [],
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
        $districts = District::query()->where('city_id', $cityId)
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])->keyBy('id');
        $counts = array_fill_keys($districts->keys()->all(), 0);
        $result['district_count'] = $districts->count();
        $result['missing_district'] = 0;
        foreach (array_chunk(array_column($matches, 'id'), 500) as $ids) {
            // Reuse the public city baseline; never count hidden venues or
            // infer districts from address text. No query per district.
            foreach (Venue::query()->whereIn('id', $ids)->with('location.address')->get() as $venue) {
                $districtId = $venue->location?->address?->district_id;
                if ($districtId === null || ! array_key_exists($districtId, $counts)) {
                    $result['missing_district']++;
                } else {
                    $counts[$districtId]++;
                }
            }
        }
        foreach ($districts as $district) {
            $result['districts'][] = [
                'id' => (int) $district->id,
                'name' => $district->name,
                'matching' => $counts[$district->id],
            ];
        }

        return $result;
    }
}
