<?php

namespace App\Modules\VenueBooking\Domain\Enums;

enum VenueRentalPreRequestStatus: string
{
    case ACTIVE = 'active';
    case RESOLVED = 'resolved';
    case WITHDRAWN = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активна',
            self::RESOLVED => 'Согласована',
            self::WITHDRAWN => 'Отозвана',
        };
    }
}
