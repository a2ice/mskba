<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingPaymentRejected
{
    public function __construct(public int $bookingId, public int $paymentAttemptId, public ?string $messageId = null) {}
}
