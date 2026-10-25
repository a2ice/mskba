<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueCourtEnvironmentEnum: string
{
    case INDOOR = 'indoor';
    case OUTDOOR = 'outdoor';

    public function label(): string
    {
        return match ($this) {
            self::INDOOR => 'Крытая площадка',
            self::OUTDOOR => 'Уличная площадка',
        };
    }
}
