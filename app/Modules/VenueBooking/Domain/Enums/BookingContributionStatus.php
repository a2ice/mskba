<?php

namespace App\Modules\VenueBooking\Domain\Enums;

enum BookingContributionStatus: string
{
    case ACTIVE = 'active';
    case REPLACED = 'replaced';
    case WITHDRAWN = 'withdrawn';
}
