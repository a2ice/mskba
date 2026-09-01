<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Events\ConfirmedBookingCancellationApplied;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\IdempotentVenueBookingCommand;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentExpired;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
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
        private IdempotentVenueBookingCommand $commands,
        private VenueBookingOutbox $outbox,
    ) {}

    public function handle(int $bookingId, Actor $actor, ?string $reason = null, ?int $expectedVersion = null, ?string $idempotencyKey = null, ?string $correlationId = null): VenueBooking
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);

        return $this->commands->execute('venue_booking.cancel', $actor, [
            'booking_id' => $bookingId, 'reason' => $reason, 'expected_version' => $expectedVersion,
        ], fn (): VenueBooking => $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $reason, $expectedVersion): VenueBooking {
            $this->authorization->assertCanCancel($actor, $booking, $venue);
            $wasConfirmed = $booking->status === VenueBookingStatusEnum::CONFIRMED;
            $paymentAttempt = VenueBookingPaymentAttempt::query()->where('venue_booking_id', $booking->id)->lockForUpdate()->first();
            if ($paymentAttempt !== null && in_array($paymentAttempt->status, [VenueBookingPaymentState::WINDOW_OPEN, VenueBookingPaymentState::CLAIMED], true)) {
                $paymentAttempt->update(['status' => VenueBookingPaymentState::EXPIRED, 'expired_at' => now()]);
                $booking->forceFill(['payment_state' => VenueBookingPaymentState::EXPIRED])->save();
                $this->outbox->record($booking->id, VenueBookingPaymentExpired::class, ['payment_attempt_id' => $paymentAttempt->id]);
            }
            $this->lifecycle->cancel($booking, $actor, CarbonImmutable::now(), $reason, $expectedVersion);
            if ($wasConfirmed) {
                $event = Event::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
                if ($event !== null && $event->status !== EventStatusEnum::CANCELLED) {
                    $event->games()->whereNotIn('status', [GameStatusEnum::CANCELLED, GameStatusEnum::COMPLETED])->update([
                        'status' => GameStatusEnum::CANCELLED, 'cancelled_at' => now(),
                        'cancelled_by_actor_id' => $actor->id,
                        'cancellation_reason' => 'Связанная бронь отменена.', 'updated_at' => now(),
                    ]);
                    $event->forceFill([
                        'status' => EventStatusEnum::CANCELLED, 'cancelled_at' => now(),
                        'cancelled_by_actor_id' => $actor->id,
                        'cancellation_reason' => $reason ?: 'Связанная бронь отменена.',
                    ])->save();
                    DB::afterCommit(static function () use ($event, $booking): void {
                        event(new ConfirmedBookingCancellationApplied($event->id, $booking->id));
                        event(new EventChanged($event->id));
                    });
                }
            }
            $this->outbox->record($booking->id, VenueBookingCancelled::class);

            return $booking->fresh('transitions');
        }), $idempotencyKey, $correlationId);
    }
}
