<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentClaimed;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class ClaimVenueBookingPaymentHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private FeatureFlags $features, private VenueBookingOutbox $outbox) {}

    /** @param array<string, string|null> $evidence */
    public function handle(int $bookingId, int $attemptId, Actor $actor, array $evidence): VenueBookingPaymentAttempt
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking) use ($attemptId, $actor, $evidence): VenueBookingPaymentAttempt {
            if ($actor->user_id === null || $actor->user_id !== $booking->requester_user_id) {
                throw new VenueBookingTransitionException('Только заявитель может сообщить об оплате.', 'BOOKING_FORBIDDEN');
            }
            $attempt = VenueBookingPaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->assertAttempt($attempt, $booking);
            if ($attempt->status === VenueBookingPaymentState::CLAIMED) {
                return $attempt;
            }
            if ($booking->status !== VenueBookingStatusEnum::HELD || $attempt->status !== VenueBookingPaymentState::WINDOW_OPEN) {
                throw new VenueBookingTransitionException('Сообщить об оплате сейчас нельзя.', 'PAYMENT_CLAIM_UNAVAILABLE');
            }
            if (! now()->lessThan($attempt->window_expires_at)) {
                throw new VenueBookingTransitionException('Платёжное окно истекло.', 'PAYMENT_WINDOW_EXPIRED');
            }

            $attempt->update([
                'status' => VenueBookingPaymentState::CLAIMED,
                'claimed_by_actor_id' => $actor->id,
                'evidence_metadata' => array_filter($evidence, static fn ($value) => $value !== null && $value !== ''),
                'claimed_at' => now(),
            ]);
            $booking->forceFill(['payment_state' => VenueBookingPaymentState::CLAIMED, 'optimistic_version' => $booking->optimistic_version + 1])->save();
            $this->outbox->record($booking->id, VenueBookingPaymentClaimed::class, ['payment_attempt_id' => $attempt->id]);

            return $attempt->fresh();
        });
    }

    private function assertAttempt(VenueBookingPaymentAttempt $attempt, VenueBooking $booking): void
    {
        if ($attempt->venue_booking_id !== $booking->id) {
            throw new VenueBookingTransitionException('Платёжная попытка не относится к этой брони.', 'PAYMENT_BOOKING_MISMATCH');
        }
    }
}
