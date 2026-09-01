<?php

namespace App\Modules\Coordination\Domain\Exceptions;

use DomainException;

final class VenueBookingAttendanceException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'ATTENDANCE_UNAVAILABLE')
    {
        parent::__construct($message);
    }
}
