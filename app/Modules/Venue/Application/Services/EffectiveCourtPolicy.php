<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

final class EffectiveCourtPolicy
{
    /** @return array<string, mixed> */
    public function resolve(Venue $venue, VenueCourt $court, ?VenueBookingPolicy $policy = null): array
    {
        if ((int) $court->venue_id !== (int) $venue->id || $court->trashed()) {
            throw new \InvalidArgumentException('Зал не принадлежит выбранной площадке.');
        }

        $venueStatus = $venue->operational_status;
        $courtStatus = $court->operational_status ?? $venueStatus;
        $isOpen = $venueStatus === VenueOperationalStatusEnum::ACTIVE
            && $courtStatus === VenueOperationalStatusEnum::ACTIVE;
        $paid = $court->requires_payment
            ?? ($policy?->requires_payment ?? $venue->requires_payment ?? false);
        $approval = $court->requires_booking_approval ?? (bool) $venue->requires_booking_approval;
        $bookingRequired = $court->requires_booking
            ?? ($venue->requires_booking ?? ($paid || $approval));

        return [
            'operational_status' => $courtStatus?->value ?? 'active',
            'is_open' => $isOpen,
            'requires_payment' => (bool) $paid,
            'requires_booking' => (bool) $bookingRequired,
            'requires_booking_approval' => (bool) $approval,
            'rental_enabled' => $isOpen && (bool) ($policy?->is_enabled ?? false)
                && ($court->rental_enabled ?? true),
            'whole_price_per_step_minor' => $court->whole_price_per_step_minor
                ?? $policy?->whole_price_per_step_minor,
            'half_price_per_step_minor' => $court->half_price_per_step_minor
                ?? $policy?->half_price_per_step_minor,
            'currency' => $policy?->currency,
            'time_step_minutes' => $policy?->time_step_minutes,
        ];
    }
}
