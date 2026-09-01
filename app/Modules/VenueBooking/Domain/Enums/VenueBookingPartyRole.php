<?php

namespace App\Modules\VenueBooking\Domain\Enums;

enum VenueBookingPartyRole: string
{
    case APPLICANT = 'applicant';
    case VENUE_REPRESENTATIVE = 'venue_representative';
}
