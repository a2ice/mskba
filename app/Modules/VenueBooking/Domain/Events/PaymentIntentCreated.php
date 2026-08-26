<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class PaymentIntentCreated
{
    public function __construct(public int $bookingId, public int $paymentAttemptId, public string $provider) {}
}
