<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueCourtEnvironmentEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

/** Internal, read-only inventory; not a live availability or public price feed. */
final readonly class RentalSeoInventory
{
    public function __construct(private EffectiveCourtPolicy $effectivePolicies) {}

    public function snapshot(): array
    {
        $groups = [];
        $totals = $this->emptyCounters();

        // Match guest catalogue visibility, not owner previews or private venues.
        Venue::query()->where('is_published', true)
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->with(['location.address', 'courts'])
            ->chunkById(100, function ($venues) use (&$groups, &$totals): void {
                $policies = VenueBookingPolicy::query()
                    ->whereIn('venue_id', $venues->pluck('id'))
                    ->where('active_marker', true)
                    ->get()->keyBy('venue_id');

                foreach ($venues as $venue) {
                    $address = $venue->location?->address;
                    $cityId = $address?->city_id;
                    $districtId = $address?->district_id;
                    $key = ($cityId ?? 'unknown').':'.($districtId ?? 'unknown');
                    $groups[$key] ??= [
                        'city_id' => $cityId,
                        'district_id' => $districtId,
                        ...$this->emptyCounters(),
                    ];
                    $counts = $this->emptyCounters();
                    $counts['venues'] = 1;

                    foreach ($venue->courts as $court) {
                        if ($court->environment !== VenueCourtEnvironmentEnum::INDOOR) {
                            continue;
                        }
                        $counts['indoor_courts']++;
                        $policy = $policies->get($venue->id);
                        $effective = $this->effectivePolicies->resolve($venue, $court, $policy);
                        $payment = $court->requires_payment ?? $policy?->requires_payment ?? $venue->requires_payment;
                        if ($payment === null) {
                            $counts['unknown_payment_indoor_courts']++;
                        }
                        if (! $effective['is_open']) {
                            continue;
                        }
                        $counts['open_indoor_courts']++;
                        if ($payment === true) {
                            $counts['paid_open_indoor_courts']++;
                        }
                        // Configured rental is not a promise of available slots,
                        // a final quote, or operational eligibility to transact.
                        if ($this->effectivePolicies->hasConfiguredRental($court, $effective)) {
                            $counts['rental_configured_indoor_courts']++;
                            if ($payment === true) {
                                $counts['paid_rental_configured_indoor_courts']++;
                            }
                        }
                    }

                    $counts['venues_with_configured_indoor_rental'] = (int) ($counts['rental_configured_indoor_courts'] > 0);
                    foreach ($counts as $metric => $value) {
                        $groups[$key][$metric] += $value;
                        $totals[$metric] += $value;
                    }
                }
            });

        ksort($groups, SORT_NATURAL);

        return [
            'environment' => app()->environment(),
            'rental_flow_feature_enabled' => (bool) config('features.venue_rental.rental_flow', false),
            'totals' => $totals,
            'geography' => array_values($groups),
        ];
    }

    private function emptyCounters(): array
    {
        return [
            'venues' => 0,
            'indoor_courts' => 0,
            'open_indoor_courts' => 0,
            'paid_open_indoor_courts' => 0,
            'unknown_payment_indoor_courts' => 0,
            'rental_configured_indoor_courts' => 0,
            'paid_rental_configured_indoor_courts' => 0,
            'venues_with_configured_indoor_rental' => 0,
        ];
    }
}
