<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOperationalStatusEnum: string
{
    case ACTIVE = 'active';
    case TEMPORARILY_CLOSED = 'temporarily_closed';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Работает',
            self::TEMPORARILY_CLOSED => 'Временно закрыта',
        };
    }
}
