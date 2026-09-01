<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingOutbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Events\PaymentFailed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPaymentConfirmed;
use App\Modules\VenueBooking\Domain\Exceptions\InvalidPaymentWebhookException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentWebhook;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class HandlePaymentWebhook
{
    public function __construct(private PaymentProviderPort $provider, private LockedVenueBooking $lockedBooking, private VenueBookingOutbox $outbox, private FeatureFlags $features) {}

    /** @param array<string, mixed> $payload
     * @param  array<string, string>  $headers
     */
    public function handle(string $provider, array $payload, array $headers): VenueBookingPaymentWebhook
    {
        $this->features->ensureEnabled(VenueRentalFeature::PAYMENT_PORT);
        if ($provider !== $this->provider->name()) {
            throw new InvalidPaymentWebhookException('Неизвестный платёжный провайдер.');
        }

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            $event = $this->provider->verifyWebhook($payload, $headers);
        } catch (InvalidPaymentWebhookException $exception) {
            VenueBookingPaymentWebhook::query()->firstOrCreate(
                ['provider' => $provider, 'provider_event_id' => (string) ($payload['event_id'] ?? $hash)],
                ['payload_hash' => $hash, 'signature_valid' => false, 'status' => 'rejected', 'failure_reason' => $exception->getMessage(), 'processed_at' => now()],
            );
            throw $exception;
        }

        $receipt = VenueBookingPaymentWebhook::query()->firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $event->eventId],
            ['payload_hash' => $hash, 'signature_valid' => true, 'safe_payload' => $event->safePayload, 'status' => 'received'],
        );
        if ($receipt->status === 'processed') {
            return $receipt;
        }

        $attempt = VenueBookingPaymentAttempt::query()
            ->where('provider', $provider)->where('provider_reference', $event->intentReference)->first();
        if ($attempt === null) {
            return $this->fail($receipt, 'Платёжный intent не найден.');
        }

        $processed = $this->lockedBooking->run($attempt->venue_booking_id, function (VenueBooking $booking) use ($attempt, $event, $receipt, $provider): VenueBookingPaymentWebhook {
            $lockedAttempt = VenueBookingPaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $lockedReceipt = VenueBookingPaymentWebhook::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($lockedReceipt->status === 'processed') {
                return $lockedReceipt;
            }
            if ($event->bookingReference !== $booking->public_id
                || $event->amountMinor !== $lockedAttempt->amount_minor
                || strtoupper($event->currency) !== $lockedAttempt->currency
                || $event->merchantReference !== $lockedAttempt->merchant_reference) {
                return $this->markFailed($lockedReceipt, 'Параметры подтверждения не совпадают с intent.');
            }

            if ($event->status === VenueBookingPaymentState::CONFIRMED && $lockedAttempt->status !== VenueBookingPaymentState::CONFIRMED) {
                $lockedAttempt->update(['status' => VenueBookingPaymentState::CONFIRMED, 'provider_checked_at' => now(), 'review_reason' => 'server_to_server']);
                $booking->forceFill(['payment_state' => VenueBookingPaymentState::CONFIRMED, 'optimistic_version' => $booking->optimistic_version + 1])->save();
                $this->outbox->record($booking->id, VenueBookingPaymentConfirmed::class, ['payment_attempt_id' => $lockedAttempt->id, 'source' => 'provider_webhook']);
            } elseif (in_array($event->status, [VenueBookingPaymentState::REJECTED, VenueBookingPaymentState::EXPIRED], true)
                && $lockedAttempt->status !== VenueBookingPaymentState::CONFIRMED) {
                $lockedAttempt->update(['status' => $event->status, 'provider_checked_at' => now()]);
                DB::afterCommit(static fn () => event(new PaymentFailed($booking->id, $lockedAttempt->id, $provider)));
            }

            $lockedReceipt->update(['status' => 'processed', 'processed_at' => now()]);

            return $lockedReceipt->fresh();
        });
        if ($processed->status === 'failed') {
            throw new InvalidPaymentWebhookException((string) $processed->failure_reason);
        }

        return $processed;
    }

    private function fail(VenueBookingPaymentWebhook $receipt, string $reason): never
    {
        $receipt->update(['status' => 'failed', 'failure_reason' => $reason, 'processed_at' => now()]);

        throw new InvalidPaymentWebhookException($reason);
    }

    private function markFailed(VenueBookingPaymentWebhook $receipt, string $reason): VenueBookingPaymentWebhook
    {
        $receipt->update(['status' => 'failed', 'failure_reason' => $reason, 'processed_at' => now()]);

        return $receipt->fresh();
    }
}
