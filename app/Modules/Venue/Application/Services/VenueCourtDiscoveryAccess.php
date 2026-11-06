<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

/**
 * Public catalogue discovery uses the effective conditions of actual courts.
 * Unknown payment conditions stay unknown rather than becoming "free".
 */
final readonly class VenueCourtDiscoveryAccess
{
    public function __construct(private EffectiveCourtPolicy $effectivePolicies) {}

    /**
     * @return array{free: array<int, array<string, mixed>>, paid: array<int, array<string, mixed>>, approval: array<int, array<string, mixed>>, unknown: array<int, array<string, mixed>>}
     */
    public function classify(Venue $venue, ?VenueBookingPolicy $policy = null): array
    {
        $categories = ['free' => [], 'paid' => [], 'approval' => [], 'unknown' => []];

        foreach ($venue->courts as $court) {
            if (! $court instanceof VenueCourt) {
                continue;
            }

            $effective = $this->effectivePolicies->resolve($venue, $court, $policy);
            if (! $effective['is_open']) {
                continue;
            }

            // EffectiveCourtPolicy resolves absent payment settings to false for
            // booking compatibility; discovery must preserve an unknown state.
            $payment = $court->requires_payment
                ?? $policy?->requires_payment
                ?? $venue->requires_payment;

            $item = [
                'court_id' => (int) $court->id,
                'name' => $court->publicName(),
                'url' => route('venues.courts.show', [
                    $venue->routeIdentifier(), $court->routeIdentifier(),
                ]),
                'requires_booking' => (bool) $effective['requires_booking'],
                'requires_booking_approval' => (bool) $effective['requires_booking_approval'],
            ];

            if ($payment === null) {
                $categories['unknown'][] = $item;
            } else {
                $categories[$payment ? 'paid' : 'free'][] = $item;
            }

            if ($effective['requires_booking_approval']) {
                $categories['approval'][] = $item;
            }
        }

        return $categories;
    }
}
