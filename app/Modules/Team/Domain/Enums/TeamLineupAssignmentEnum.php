<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamLineupAssignmentEnum: string
{
    case STARTER = 'starter';
    case RESERVE = 'reserve';

    public function label(): string
    {
        return match ($this) {
            self::STARTER => 'Основной состав',
            self::RESERVE => 'Запас',
        };
    }
}
