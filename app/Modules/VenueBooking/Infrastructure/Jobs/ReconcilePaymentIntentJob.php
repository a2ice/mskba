<?php

namespace App\Modules\VenueBooking\Infrastructure\Jobs;

use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\PaymentFailed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentConfirmed;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReconcilePaymentIntentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 30, 120];

    public function __construct(public readonly int $paymentAttemptId) {}

    public function handle(PaymentProviderPort $provider, LockedVenueBooking $lockedBooking, VenueBookingOutbox $outbox): void
    {
        Cache::lock('venue-payment-reconcile:'.$this->paymentAttemptId, 60)->get(function () use ($provider, $lockedBooking, $outbox): void {
            $attempt = VenueBookingPaymentAttempt::query()->find($this->paymentAttemptId);
            if ($attempt === null || $attempt->provider !== $provider->name() || $attempt->provider_reference === null || $attempt->status === VenueBookingPaymentState::CONFIRMED) {
                return;
            }
            $result = $provider->queryStatus($attempt->provider_reference);
            if ($result->reference !== $attempt->provider_reference) {
                throw new \RuntimeException('Payment provider returned a mismatched intent reference.');
            }

            $lockedBooking->run($attempt->venue_booking_id, function (VenueBooking $booking) use ($attempt, $result, $outbox, $provider): void {
                $lockedAttempt = VenueBookingPaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($lockedAttempt->status === VenueBookingPaymentState::CONFIRMED) {
                    return;
                }
                $lockedAttempt->update(['provider_checked_at' => now()]);
                if ($result->status === VenueBookingPaymentState::CONFIRMED) {
                    $lockedAttempt->update(['status' => VenueBookingPaymentState::CONFIRMED, 'review_reason' => 'provider_reconciliation']);
                    $booking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED, 'optimistic_version' => $booking->optimistic_version + 1])->save();
                    $outbox->record($booking->id, VenueBookingPaymentConfirmed::class, ['payment_attempt_id' => $lockedAttempt->id, 'source' => 'provider_reconciliation']);
                } elseif (in_array($result->status, [VenueBookingPaymentState::REJECTED, VenueBookingPaymentState::EXPIRED], true)) {
                    $lockedAttempt->update(['status' => $result->status]);
                    DB::afterCommit(static fn () => event(new PaymentFailed($booking->id, $lockedAttempt->id, $provider->name())));
                }
            });
        });
    }
}
