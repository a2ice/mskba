<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueUserRestrictionTypeEnum: string
{
    case OWNERSHIP_CLAIM = 'ownership_claim';
    case RENTAL_REQUEST = 'rental_request';

    public function label(): string
    {
        return match ($this) {
            self::OWNERSHIP_CLAIM => 'Заявки на управление',
            self::RENTAL_REQUEST => 'Заявки на аренду',
        };
    }
}
