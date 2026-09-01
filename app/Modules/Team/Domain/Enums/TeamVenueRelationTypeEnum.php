<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamVenueRelationTypeEnum: string
{
    case DESIRED = 'desired';
    case CONFIRMED = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::DESIRED => 'Желаемая',
            self::CONFIRMED => 'Подтверждённая',
        };
    }
}
