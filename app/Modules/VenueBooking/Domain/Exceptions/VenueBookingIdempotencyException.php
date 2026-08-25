<?php

namespace App\Modules\VenueBooking\Domain\Exceptions;

final class VenueBookingIdempotencyException extends VenueBookingTransitionException
{
    public function __construct()
    {
        parent::__construct('Ключ идемпотентности уже использован с другими данными.', 'IDEMPOTENCY_KEY_REUSED');
    }
}
