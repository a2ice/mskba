<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CancelVenueBookingHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private VenueBookingAuthorization $authorization,
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
    ) {}

    public function handle(int $bookingId, Actor $actor, ?string $reason = null, ?int $expectedVersion = null): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $reason, $expectedVersion): VenueBooking {
            $this->authorization->assertCanCancel($actor, $booking, $venue);
            $this->lifecycle->cancel($booking, $actor, CarbonImmutable::now(), $reason, $expectedVersion);
            DB::afterCommit(static fn () => event(new VenueBookingCancelled($booking->id)));

            return $booking->fresh('transitions');
        });
    }
}
