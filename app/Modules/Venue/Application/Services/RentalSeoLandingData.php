<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Location\Domain\Models\City;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;

/**
 * Public-safe read model for a future commercial landing page.
 * No HTTP route, publication or indexing is enabled by this service.
 */
final readonly class RentalSeoLandingData
{
    public function __construct(private ListVenuesHandler $venues) {}

    public function forGeography(string $cityAlias, ?string $districtAlias = null): ?array
    {
        $city = City::query()->where('alias', $cityAlias)->first();
        if ($city === null) {
            return null;
        }

        $district = $districtAlias === null
            ? null
            : $city->districts()->where('alias', $districtAlias)->first();
        if ($districtAlias !== null && $district === null) {
            return null;
        }

        $filters = [
            'city_id' => $city->id,
            'type' => VenueTypeEnum::SPORTS_HALL->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
        ];
        if ($district !== null) {
            $filters['district_id'] = $district->id;
        }

        // Deliberately use guest visibility even for an authenticated owner.
        // The landing must never display owner-only previews or private inventory.
        $items = $this->venues->handle(null, filters: $filters, configuredRentalOnly: true);

        return [
            'city' => ['id' => $city->id, 'name' => $city->name, 'alias' => $city->alias],
            'district' => $district === null ? null : [
                'id' => $district->id, 'name' => $district->name, 'alias' => $district->alias,
            ],
            'venues' => $items,
            'venue_count' => count($items),
            'rental_flow_enabled' => (bool) config('features.venue_rental.rental_flow', false),
            // Explicitly a broader discovery fallback, not a guarantee of rental.
            'catalog_url' => route('venues', $filters, absolute: false),
        ];
    }
}
