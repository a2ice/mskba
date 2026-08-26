<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Payments\CreatePaymentIntentData;
use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\PaymentIntentCreated;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentWindowOpened;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OpenVenueBookingPaymentWindowHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features, private VenueBookingOutbox $outbox, private PaymentProviderPort $payments) {}

    public function handle(int $bookingId, Actor $actor, string $method, string $instructions): VenueBookingPaymentAttempt
    {
        $this->features->ensureEnabled(VenueRentalFeature::EXTERNAL_PAYMENT);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $method, $instructions): VenueBookingPaymentAttempt {
            $this->authorization->assertCanConfirmPayment($actor, $venue);
            if ($booking->status !== VenueBookingStatusEnum::HELD || $booking->effective_protection_until === null || ! now()->lessThan($booking->effective_protection_until)) {
                throw new VenueBookingTransitionException('Платёжное окно доступно только во время действующего удержания.', 'HOLD_EXPIRED');
            }
            if (! (bool) data_get($booking->quote_snapshot, 'policy.requires_payment', false)) {
                throw new VenueBookingTransitionException('Эта бронь не требует оплаты.', 'PAYMENT_NOT_REQUIRED');
            }

            $existing = VenueBookingPaymentAttempt::query()->where('venue_booking_id', $booking->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $windowMinutes = (int) data_get($booking->quote_snapshot, 'policy.payment_window_minutes', 0);
            $amount = (int) data_get($booking->quote_snapshot, 'pricing.amount_minor', -1);
            $currency = (string) data_get($booking->quote_snapshot, 'pricing.currency', '');
            if ($windowMinutes < 1 || $amount < 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new VenueBookingTransitionException('В расчёте отсутствуют корректные платёжные условия.', 'INVALID_QUOTE_SNAPSHOT');
            }

            $now = CarbonImmutable::now();
            $deadline = $now->addMinutes($windowMinutes);
            $providerIntent = $this->features->enabled(VenueRentalFeature::PAYMENT_PORT)
                ? $this->payments->createIntent(new CreatePaymentIntentData(
                    $booking->public_id, $amount, $currency,
                    (string) config('services.venue_rental_payment.merchant'),
                    $booking->public_id,
                    $deadline,
                ))
                : null;
            $attempt = VenueBookingPaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(),
                'venue_booking_id' => $booking->id,
                'amount_minor' => $amount,
                'currency' => $currency,
                'method' => $method,
                'provider' => $providerIntent === null ? 'external_manual' : $this->payments->name(),
                'provider_reference' => $providerIntent?->reference,
                'provider_idempotency_key' => $providerIntent === null ? null : $booking->public_id,
                'merchant_reference' => $providerIntent === null ? null : (string) config('services.venue_rental_payment.merchant'),
                'provider_metadata' => $providerIntent?->safeMetadata,
                'payment_instructions' => trim($instructions),
                'status' => VenueBookingPaymentState::WINDOW_OPEN,
                'window_opened_at' => $now,
                'window_expires_at' => $deadline,
                'opened_by_actor_id' => $actor->id,
            ]);
            $effectiveDeadline = $booking->effective_protection_until->greaterThan($deadline)
                ? $booking->effective_protection_until
                : $deadline;
            $nextVersion = $booking->optimistic_version + 1;
            $booking->forceFill([
                'payment_state' => VenueBookingPaymentState::WINDOW_OPEN,
                'payment_window_expires_at' => $deadline,
                'effective_protection_until' => $effectiveDeadline,
                'optimistic_version' => $nextVersion,
            ])->save();

            $this->outbox->record($booking->id, VenueBookingPaymentWindowOpened::class, ['payment_attempt_id' => $attempt->id]);
            if ($providerIntent !== null) {
                DB::afterCommit(fn () => event(new PaymentIntentCreated($booking->id, $attempt->id, $this->payments->name())));
            }
            DB::afterCommit(static function () use ($booking, $nextVersion, $effectiveDeadline): void {
                ExpireVenueBookingIfDueJob::dispatch($booking->id, $nextVersion, $effectiveDeadline->toIso8601String())->delay($effectiveDeadline);
            });

            return $attempt;
        });
    }
}
