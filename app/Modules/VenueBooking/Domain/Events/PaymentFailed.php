<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class PaymentFailed
{
    public function __construct(public int $bookingId, public int $paymentAttemptId, public string $provider) {}
}
