<?php

namespace App\Modules\VenueBooking\Domain\Enums;

enum VenueBookingPaymentState: string
{
    case NOT_REQUIRED = 'not_required';
    case NOT_STARTED = 'not_started';
    case READY = 'ready';
    case WINDOW_OPEN = 'window_open';
    case CLAIMED = 'claimed';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case DISPUTED = 'disputed';
    case EXPIRED = 'expired';
}
