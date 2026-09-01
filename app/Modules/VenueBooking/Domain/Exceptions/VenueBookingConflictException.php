<?php

namespace App\Modules\VenueBooking\Domain\Exceptions;

final class VenueBookingConflictException extends VenueBookingTransitionException
{
    /** @param list<string> $suggestedStartsAt */
    public function __construct(public readonly array $suggestedStartsAt = [])
    {
        parent::__construct('Выбранный интервал уже занят.', 'BOOKING_CONFLICT');
    }
}
