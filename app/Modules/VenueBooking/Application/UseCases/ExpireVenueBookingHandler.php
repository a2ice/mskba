<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;

final readonly class ExpireVenueBookingHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
        private IdempotentVenueBookingCommand $commands,
        private VenueBookingOutbox $outbox,
    ) {}

    public function handle(int $bookingId, Actor $systemActor, ?int $expectedVersion = null, ?string $idempotencyKey = null, ?string $correlationId = null): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->commands->execute('venue_booking.expire', $systemActor, [
            'booking_id' => $bookingId, 'expected_version' => $expectedVersion,
        ], fn (): VenueBooking => $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($systemActor, $expectedVersion): VenueBooking {
            $this->lifecycle->expire($booking, $systemActor, CarbonImmutable::now(), $expectedVersion);
            $this->outbox->record($booking->id, VenueBookingExpired::class);

            return $booking->fresh('transitions');
        }), $idempotencyKey, $correlationId);
    }
}
