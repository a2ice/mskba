<?php

namespace App\Modules\Event\Domain\Enums;

enum GameLineupRoleEnum: string
{
    case STARTER = 'starter';
    case BENCH = 'bench';

    public function label(): string
    {
        return match ($this) {
            self::STARTER => 'Стартовый состав',
            self::BENCH => 'Запас',
        };
    }
}
