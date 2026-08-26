<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentConfirmed;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class ConfirmVenueBookingPaymentHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features, private VenueBookingOutbox $outbox) {}

    public function handle(int $bookingId, int $attemptId, Actor $actor, ?string $reason = null): VenueBookingPaymentAttempt
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($attemptId, $actor, $reason): VenueBookingPaymentAttempt {
            $this->authorization->assertCanConfirmPayment($actor, $venue);
            $attempt = VenueBookingPaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttempt($attempt, $booking);
            if ($attempt->status === VenueBookingPaymentState::CONFIRMED) {
                return $attempt;
            }
            if ($booking->status !== VenueBookingStatusEnum::HELD || $attempt->status !== VenueBookingPaymentState::CLAIMED) {
                throw new VenueBookingTransitionException('Оплата не ожидает подтверждения.', 'PAYMENT_CONFIRM_UNAVAILABLE');
            }
            if (! now()->lessThan($attempt->window_expires_at)) {
                throw new VenueBookingTransitionException('Платёжное окно истекло.', 'PAYMENT_WINDOW_EXPIRED');
            }
            $expectedAmount = (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', -1);
            $expectedCurrency = (string) data_get($booking->quote_snapshot, 'pricing.currency', '');
            if ($attempt->amount_minor !== $expectedAmount || $attempt->currency !== $expectedCurrency) {
                throw new VenueBookingTransitionException('Сумма платёжной попытки не совпадает с расчётом.', 'INVALID_PAYMENT_AMOUNT');
            }

            $attempt->update(['status' => VenueBookingPaymentState::CONFIRMED, 'reviewed_by_actor_id' => $actor->id, 'review_reason' => $reason, 'reviewed_at' => now()]);
            $booking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED, 'optimistic_version' => $booking->optimistic_version + 1])->save();
            $this->outbox->record($booking->id, VenueBookingPaymentConfirmed::class, ['payment_attempt_id' => $attempt->id]);

            return $attempt->fresh();
        }, lockConflicts: true);
    }

    private function assertAttempt(VenueBookingPaymentAttempt $attempt, VenueBooking $booking): void
    {
        if ($attempt->venue_booking_id !== $booking->id) {
            throw new VenueBookingTransitionException('Платёжная попытка не относится к этой брони.', 'PAYMENT_BOOKING_MISMATCH');
        }
    }
}
