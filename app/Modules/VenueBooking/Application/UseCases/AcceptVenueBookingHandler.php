<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;

final readonly class AcceptVenueBookingHandler
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

        return $this->commands->execute('venue_booking.accept', $actor, [
            'booking_id' => $bookingId,
            'expected_version' => $expectedVersion,
        ], fn (): VenueBooking => $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $expectedVersion): VenueBooking {
            $this->authorization->assertCanConfirmBooking($actor, $venue, $booking);
            $this->lifecycle->hold($booking, $actor, CarbonImmutable::now(), $expectedVersion);
            if ((bool) data_get($booking->quote_snapshot, 'policy.invoice_workflow', false)) {
                app(\App\Modules\VenueBooking\Application\Services\VenueRentalInvoiceManager::class)
                    ->autoIssueBuyerWithinLock($booking, $actor);
            }
            if (! app(VenueBookingBackofficeAccess::class)->canManage($actor->user)) {
                $booking->parties()->firstOrCreate([
                    'user_id' => $actor->user_id,
                    'role' => VenueBookingPartyRole::VENUE_REPRESENTATIVE,
                ]);
            }
            $this->outbox->record($booking->id, VenueBookingHeld::class);

            return $booking->fresh(['transitions', 'parties']);
        }, lockConflicts: true), $idempotencyKey, $correlationId);
    }
}
