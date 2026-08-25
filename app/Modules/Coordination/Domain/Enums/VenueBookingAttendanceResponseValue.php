<?php

namespace App\Modules\Coordination\Domain\Enums;

enum VenueBookingAttendanceResponseValue: string
{
    case YES = 'yes';
    case NO = 'no';
    case MAYBE = 'maybe';

    public function label(): string
    {
        return match ($this) {
            self::YES => 'Пойду',
            self::NO => 'Не пойду',
            self::MAYBE => 'Возможно',
        };
    }
}
