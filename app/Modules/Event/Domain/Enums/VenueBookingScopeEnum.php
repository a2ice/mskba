<?php

namespace App\Modules\Event\Domain\Enums;

enum VenueBookingScopeEnum: string
{
    case WHOLE = 'whole';
    case HALF_A = 'half_a';
    case HALF_B = 'half_b';

    public function label(): string
    {
        return match ($this) {
            self::WHOLE => 'Вся площадка',
            self::HALF_A => 'Половина A',
            self::HALF_B => 'Половина B',
        };
    }
}
