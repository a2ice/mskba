<?php

namespace App\Modules\VenueBooking\Domain\Enums;

enum VenueBookingExtensionStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает решения',
            self::APPROVED => 'Одобрено',
            self::REJECTED => 'Отклонено',
            self::CANCELLED => 'Отменено',
        };
    }
}
