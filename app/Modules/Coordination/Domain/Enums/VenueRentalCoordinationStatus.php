<?php

namespace App\Modules\Coordination\Domain\Enums;

enum VenueRentalCoordinationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Сбор открыт',
            self::CLOSED => 'Сбор закрыт',
            self::CANCELLED => 'Сбор отменён',
            self::CONVERTED => 'Заявка на аренду создана',
        };
    }
}
