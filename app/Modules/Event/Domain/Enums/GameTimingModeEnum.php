<?php

namespace App\Modules\Event\Domain\Enums;

enum GameTimingModeEnum: string
{
    case WHOLE_GAME = 'whole_game';
    case PERIODS = 'periods';

    public function label(): string
    {
        return match ($this) {
            self::WHOLE_GAME => 'Игра целиком',
            self::PERIODS => 'По периодам',
        };
    }
}
