<?php

namespace App\Modules\VenueBooking\Infrastructure\Payments;

use App\Modules\VenueBooking\Application\Payments\CreatePaymentIntentData;
use App\Modules\VenueBooking\Application\Payments\PaymentProviderIntent;
use App\Modules\VenueBooking\Application\Payments\PaymentProviderPort;
use App\Modules\VenueBooking\Application\Payments\VerifiedPaymentEvent;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\InvalidPaymentWebhookException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPaymentAttempt;

final class ExternalManualPaymentAdapter implements PaymentProviderPort
{
    public function name(): string
    {
        return 'external_manual';
    }

    public function createIntent(CreatePaymentIntentData $data): PaymentProviderIntent
    {
        return new PaymentProviderIntent('manual-'.$data->idempotencyKey, VenueBookingPaymentState::WINDOW_OPEN, ['channel' => 'manual_review']);
    }

    public function queryStatus(string $intentReference): PaymentProviderIntent
    {
        $attempt = VenueBookingPaymentAttempt::query()->where('provider', $this->name())->where('provider_reference', $intentReference)->firstOrFail();

        return new PaymentProviderIntent($intentReference, $attempt->status, ['channel' => 'manual_review']);
    }

    public function cancelIntent(string $intentReference): PaymentProviderIntent
    {
        return new PaymentProviderIntent($intentReference, VenueBookingPaymentState::EXPIRED, ['channel' => 'manual_review']);
    }

    public function verifyWebhook(array $payload, array $headers): VerifiedPaymentEvent
    {
        throw new InvalidPaymentWebhookException('Ручной платёжный адаптер не принимает webhook.');
    }
}
