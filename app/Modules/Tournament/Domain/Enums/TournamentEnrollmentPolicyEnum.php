<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentEnrollmentPolicyEnum: string
{
    case FIXED_POOL = 'fixed_pool';
    case CONTINUOUS = 'continuous';

    public function label(): string
    {
        return match ($this) {
            self::FIXED_POOL => 'Фиксированный состав',
            self::CONTINUOUS => 'Открытая лига',
        };
    }
}
