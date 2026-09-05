<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOwnershipStatusEnum: string
{
    case ACTIVE = 'active';
    case UNDER_REVIEW = 'under_review';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активное',
            self::UNDER_REVIEW => 'Уточняется',
            self::REVOKED => 'Аннулированное',
        };
    }
}
