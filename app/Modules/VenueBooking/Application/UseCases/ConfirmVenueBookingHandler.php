<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;

final readonly class ConfirmVenueBookingHandler
{
    public function __construct(
        private LockedVenueBooking $lockedBooking,
        private VenueBookingAuthorization $authorization,
        private VenueBookingLifecycle $lifecycle,
        private FeatureFlags $features,
        private IdempotentVenueBookingCommand $commands,
        private VenueBookingOutbox $outbox,
    ) {}

    public function handle(int $bookingId, Actor $actor, ?int $expectedVersion = null, ?string $idempotencyKey = null, ?string $correlationId = null): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->commands->execute('venue_booking.confirm', $actor, [
            'booking_id' => $bookingId, 'expected_version' => $expectedVersion,
        ], fn (): VenueBooking => $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $expectedVersion): VenueBooking {
            $this->authorization->assertCanConfirmBooking($actor, $venue, $booking);
            $this->lifecycle->confirm($booking, $actor, CarbonImmutable::now(), $expectedVersion);
            if ($booking->event_time_sync_version !== null && $booking->event_id !== null) {
                $event = \App\Modules\Event\Domain\Models\Event::query()
                    ->whereKey($booking->event_id)->where('booking_id', $booking->id)->lockForUpdate()->first();
                if ($event !== null && $event->participation_confirmation_version === $booking->event_time_sync_version) {
                    app(\App\Modules\Event\Application\Services\EventTimeComment::class)
                        ->confirmFromRental($event, $booking->starts_at, $booking->ends_at, $actor);
                }
            }
            $this->outbox->record($booking->id, VenueBookingConfirmed::class);

            return $booking->fresh('transitions');
        }, lockConflicts: true), $idempotencyKey, $correlationId);
    }
}
