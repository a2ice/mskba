<?php

namespace App\Modules\VenueBooking\Application\Payments;

use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;

final readonly class VerifiedPaymentEvent
{
    /** @param array<string, scalar|null> $safePayload */
    public function __construct(
        public string $eventId,
        public string $intentReference,
        public string $bookingReference,
        public int $amountMinor,
        public string $currency,
        public string $merchantReference,
        public VenueBookingPaymentState $status,
        public array $safePayload = [],
    ) {}
}
