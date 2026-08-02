<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueMarkingConditionEnum: string
{
    case NONE = 'none';
    case PARTIAL = 'partial';
    case GOOD = 'good';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Нет',
            self::PARTIAL => 'Неполная',
            self::GOOD => 'Хорошая',
        };
    }
}
