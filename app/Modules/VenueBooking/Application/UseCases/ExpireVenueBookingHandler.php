<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ExpireVenueBookingHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, Actor $systemActor, ?int $expectedVersion = null): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($systemActor, $expectedVersion): VenueBooking {
            $this->lifecycle->expire($booking, $systemActor, CarbonImmutable::now(), $expectedVersion);
            DB::afterCommit(static fn () => event(new VenueBookingExpired($booking->id)));

            return $booking->fresh('transitions');
        });
    }
}
