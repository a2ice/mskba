<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
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
        ?VenueCourt $court = null,
        ?array $effective = null,
    ): array {
        $fallback = (int) ($scope === VenueBookingScopeEnum::WHOLE
            ? $policy->whole_price_per_step_minor
            : $policy->half_price_per_step_minor);
        $priceColumn = $scope === VenueBookingScopeEnum::WHOLE
            ? 'whole_price_per_step_minor'
            : 'half_price_per_step_minor';
        $venueOverrides = VenueScheduleSlotPrice::query()
            ->where('venue_id', $venue->id)
            ->get()
            ->keyBy(fn (VenueScheduleSlotPrice $price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5));
        $courtOverrides = $court?->slotPrices()->get()->keyBy(
            fn ($price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5)
        ) ?? collect();
        $baseOverride = $court?->{$priceColumn};
        $paid = (bool) ($effective['requires_payment'] ?? $policy->requires_payment);
        $prices = [];
        $usesCustomPrices = false;

        for ($offset = 0; $offset < $durationMinutes; $offset += $policy->time_step_minutes) {
            $step = $localStart->addMinutes($offset);
            $key = $step->dayOfWeekIso.'|'.$step->format('H:i');
            $courtCustom = $courtOverrides->get($key)?->{$priceColumn};
            $venueCustom = $venueOverrides->get($key)?->{$priceColumn};
            // Court slot > court base > venue slot > venue base.
            $custom = $courtCustom ?? $baseOverride ?? $venueCustom;
            $prices[] = ! $paid ? 0 : ($custom === null ? $fallback : (int) $custom);
            $usesCustomPrices = $usesCustomPrices || ($paid && $custom !== null);
        }

        return [
            'amount_minor' => array_sum($prices),
            'prices_per_step_minor' => $prices,
            'uses_custom_prices' => $usesCustomPrices,
        ];
    }
}
