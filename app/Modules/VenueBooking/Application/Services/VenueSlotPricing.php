<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueScheduleSlotPrice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class VenueSlotPricing
{
    /**
     * Individual court prices apply to one effective court step. Inherited venue prices
     * always apply to one policy step, so a larger court step sums its venue substeps.
     *
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
        $venueStep = (int) $policy->time_step_minutes;
        $courtStep = (int) ($effective['time_step_minutes'] ?? $venueStep);
        if ($venueStep < 1 || $courtStep < 1 || $courtStep % $venueStep !== 0) {
            throw new InvalidArgumentException('Шаг аренды зала должен быть кратен шагу площадки.');
        }

        $column = $scope === VenueBookingScopeEnum::WHOLE
            ? 'whole_price_per_step_minor'
            : 'half_price_per_step_minor';
        $venueWeekly = VenueScheduleSlotPrice::query()->where('venue_id', $venue->id)->get()
            ->keyBy(fn (VenueScheduleSlotPrice $price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5));
        $dateFrom = $localStart->toDateString();
        $dateTo = $localStart->addMinutes($durationMinutes)->toDateString();
        $venueCalendar = $venue->calendarSlotPrices()->whereBetween('date', [$dateFrom, $dateTo])->get()
            ->keyBy(fn ($price): string => $price->date->format('Y-m-d').'|'.substr((string) $price->starts_at, 0, 5));
        $courtWeekly = $court?->slotPrices()->get()->keyBy(
            fn ($price): string => $price->day_of_week.'|'.substr((string) $price->starts_at, 0, 5)
        ) ?? collect();
        $courtCalendar = $court?->calendarSlotPrices()->whereBetween('date', [$dateFrom, $dateTo])->get()->keyBy(
            fn ($price): string => $price->date->format('Y-m-d').'|'.substr((string) $price->starts_at, 0, 5)
        ) ?? collect();

        $courtBase = $court?->{$column};
        $venueBase = (int) $policy->{$column};
        // The venue-level tariff is a minimum for each payable policy step.
        // Individual court and calendar tariffs may increase it, never lower it.
        $minimumCourtStep = max(0, $venueBase) * intdiv($courtStep, $venueStep);
        $paid = (bool) ($effective['requires_payment'] ?? $policy->requires_payment);
        $prices = [];
        $usesCustomPrices = false;

        for ($offset = 0; $offset < $durationMinutes; $offset += $courtStep) {
            $start = $localStart->addMinutes($offset);
            $dateKey = $start->format('Y-m-d|H:i');
            $weekdayKey = $start->dayOfWeekIso.'|'.$start->format('H:i');

            // An explicit court-level value overrides inherited venue prices.
            // NULL (not zero) means "inherit" for either rental scope.
            $courtCustom = $courtCalendar->get($dateKey)?->{$column}
                ?? $courtWeekly->get($weekdayKey)?->{$column}
                ?? $courtBase;

            if ($courtCustom !== null) {
                $prices[] = $paid ? max((int) $courtCustom, $minimumCourtStep) : 0;
                $usesCustomPrices = $usesCustomPrices || $paid;
                continue;
            }

            $amount = 0;
            for ($substep = 0; $substep < $courtStep; $substep += $venueStep) {
                $time = $start->addMinutes($substep);
                $key = $time->format('Y-m-d|H:i');
                $weeklyKey = $time->dayOfWeekIso.'|'.$time->format('H:i');
                $override = $venueCalendar->get($key)?->{$column}
                    ?? $venueWeekly->get($weeklyKey)?->{$column};
                if ($override !== null) {
                    $usesCustomPrices = $usesCustomPrices || $paid;
                }
                $amount += $override === null ? $venueBase : max($venueBase, (int) $override);
            }
            $prices[] = $paid ? $amount : 0;
        }

        return [
            'amount_minor' => array_sum($prices),
            'prices_per_step_minor' => $prices,
            'uses_custom_prices' => $usesCustomPrices,
        ];
    }
}
