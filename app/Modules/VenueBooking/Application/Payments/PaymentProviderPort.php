<?php

namespace App\Modules\VenueBooking\Application\Payments;

interface PaymentProviderPort
{
    public function name(): string;

    public function createIntent(CreatePaymentIntentData $data): PaymentProviderIntent;

    public function queryStatus(string $intentReference): PaymentProviderIntent;

    public function cancelIntent(string $intentReference): PaymentProviderIntent;

    /** @param array<string, mixed> $payload
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(array $payload, array $headers): VerifiedPaymentEvent;
}
