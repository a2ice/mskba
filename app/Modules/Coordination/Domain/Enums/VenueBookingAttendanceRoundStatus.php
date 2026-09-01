<?php

namespace App\Modules\Coordination\Domain\Enums;

enum VenueBookingAttendanceRoundStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Сбор ответов открыт',
            self::CLOSED => 'Сбор ответов закрыт',
        };
    }
}
