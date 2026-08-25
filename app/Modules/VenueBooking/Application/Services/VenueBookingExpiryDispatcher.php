<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\Cache;

final readonly class VenueBookingExpiryDispatcher
{
    public function __construct(private FeatureFlags $features) {}

    public function dispatchDue(int $batchSize = 100): int
    {
        if (! $this->features->enabled(VenueRentalFeature::RENTAL_FLOW)) {
            return 0;
        }
        $batchSize = max(1, min($batchSize, 500));
        $bookings = VenueBooking::query()
            ->where('flow', 'rental')
            ->where('status', VenueBookingStatusEnum::HELD)
            ->whereNotNull('effective_protection_until')
            ->where('effective_protection_until', '<=', now())
            ->orderBy('effective_protection_until')
            ->orderBy('id')
            ->limit($batchSize)
            ->get(['id', 'optimistic_version', 'effective_protection_until']);

        foreach ($bookings as $booking) {
            ExpireVenueBookingIfDueJob::dispatch(
                $booking->id,
                $booking->optimistic_version,
                $booking->effective_protection_until->toIso8601String(),
            );
        }
        if ($bookings->isNotEmpty()) {
            Cache::increment('metrics:venue_booking:expiry:scheduled', $bookings->count());
        }

        return $bookings->count();
    }
}
