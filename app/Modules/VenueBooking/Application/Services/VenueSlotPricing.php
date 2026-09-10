<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueScheduleSlotPrice;
use Carbon\CarbonImmutable;

final class VenueSlotPricing
{
    /**
     * @return array{amount_minor: int, prices_per_step_minor: array<int, int>, uses_custom_prices: bool}
     */
    public function calculate(
        Venue $venue,
        VenueBookingPolicy $policy,
        CarbonImmutable $localStart,
        int $durationMinutes,
        VenueBookingScopeEnum $scope,
    ): array {
        $fallback = (int) ($scope === VenueBookingScopeEnum::WHOLE
            ? $policy->whole_price_per_step_minor
            : $policy->half_price_per_step_minor);
        $priceColumn = $scope === VenueBookingScopeEnum::WHOLE
            ? 'whole_price_per_step_minor'
            : 'half_price_per_step_minor';
        $overrides = VenueScheduleSlotPrice::query()
            ->where('venue_id', $venue->id)
            ->get()
            ->keyBy(fn (VenueScheduleSlotPrice $price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5));
        $prices = [];
        $usesCustomPrices = false;

        for ($offset = 0; $offset < $durationMinutes; $offset += $policy->time_step_minutes) {
            $step = $localStart->addMinutes($offset);
            $override = $overrides->get($step->dayOfWeekIso.'|'.$step->format('H:i'));
            $custom = $override?->{$priceColumn};
            $prices[] = $custom === null ? $fallback : (int) $custom;
            $usesCustomPrices = $usesCustomPrices || $custom !== null;
        }

        return [
            'amount_minor' => array_sum($prices),
            'prices_per_step_minor' => $prices,
            'uses_custom_prices' => $usesCustomPrices,
        ];
    }
}
