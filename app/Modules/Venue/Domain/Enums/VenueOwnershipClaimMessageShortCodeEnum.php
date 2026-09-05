<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOwnershipClaimMessageShortCodeEnum: string
{
    case FILES_REQUESTED = 'files_requested';

    public function label(): string
    {
        return match ($this) {
            self::FILES_REQUESTED => 'Запрошены документы',
        };
    }
}
