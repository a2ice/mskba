<?php

namespace App\Modules\VenueBooking\Domain\Exceptions;

use DomainException;

final class VenueBookingTransitionException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'INVALID_BOOKING_TRANSITION')
    {
        parent::__construct($message);
    }
}
