<?php

namespace App\Modules\VenueBooking\Domain\Events;

final readonly class VenueBookingPaymentConfirmed
{
    public function __construct(public int $bookingId, public int $paymentAttemptId, public ?string $messageId = null) {}
}
