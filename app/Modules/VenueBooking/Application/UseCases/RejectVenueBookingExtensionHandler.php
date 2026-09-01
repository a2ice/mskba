<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExtensionRejected;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingExtensionRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class RejectVenueBookingExtensionHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features) {}

    public function handle(int $bookingId, int $extensionRequestId, Actor $actor, ?string $reason = null): VenueBookingExtensionRequest
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($extensionRequestId, $actor, $reason): VenueBookingExtensionRequest {
            $this->authorization->assertCommercialDecision($actor, $venue);
            $extension = VenueBookingExtensionRequest::query()->lockForUpdate()->findOrFail($extensionRequestId);
            if ($extension->venue_booking_id !== $booking->id) {
                throw new VenueBookingTransitionException('Запрос продления не относится к этой брони.', 'EXTENSION_BOOKING_MISMATCH');
            }
            if ($extension->status === VenueBookingExtensionStatus::REJECTED) {
                return $extension;
            }
            if ($extension->status !== VenueBookingExtensionStatus::PENDING) {
                throw new VenueBookingTransitionException('Запрос на продление уже закрыт.', 'EXTENSION_ALREADY_DECIDED');
            }
            $extension->update([
                'status' => VenueBookingExtensionStatus::REJECTED,
                'active_marker' => null,
                'reviewed_by_actor_id' => $actor->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ]);
            DB::afterCommit(static fn () => event(new VenueBookingExtensionRejected($booking->id, $extension->id)));

            return $extension->fresh();
        });
    }
}
