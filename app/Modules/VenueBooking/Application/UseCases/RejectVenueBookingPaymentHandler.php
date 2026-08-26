<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentRejected;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class RejectVenueBookingPaymentHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features, private VenueBookingOutbox $outbox) {}

    public function handle(int $bookingId, int $attemptId, Actor $actor, string $reason): VenueBookingPaymentAttempt
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($attemptId, $actor, $reason): VenueBookingPaymentAttempt {
            $this->authorization->assertCanConfirmPayment($actor, $venue);
            $attempt = VenueBookingPaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->venue_booking_id !== $booking->id) {
                throw new VenueBookingTransitionException('Платёжная попытка не относится к этой брони.', 'PAYMENT_BOOKING_MISMATCH');
            }
            if ($attempt->status === VenueBookingPaymentState::REJECTED) {
                return $attempt;
            }
            if ($attempt->status !== VenueBookingPaymentState::CLAIMED) {
                throw new VenueBookingTransitionException('Оплата не ожидает проверки.', 'PAYMENT_REJECT_UNAVAILABLE');
            }

            $attempt->update(['status' => VenueBookingPaymentState::REJECTED, 'reviewed_by_actor_id' => $actor->id, 'review_reason' => trim($reason), 'reviewed_at' => now()]);
            $fallbackDeadline = $booking->hold_expires_at?->isFuture() ? $booking->hold_expires_at : now();
            $booking->forceFill([
                'payment_state' => VenueBookingPaymentState::REJECTED,
                'payment_window_expires_at' => null,
                'effective_protection_until' => $fallbackDeadline,
                'optimistic_version' => $booking->optimistic_version + 1,
            ])->save();
            $this->outbox->record($booking->id, VenueBookingPaymentRejected::class, ['payment_attempt_id' => $attempt->id]);

            return $attempt->fresh();
        });
    }
}
