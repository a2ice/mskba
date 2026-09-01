<?php

namespace App\Modules\VenueBooking\Application\Payments;

final readonly class CreatePaymentIntentData
{
    public function __construct(
        public string $bookingReference,
        public int $amountMinor,
        public string $currency,
        public string $merchantReference,
        public string $idempotencyKey,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
