<?php

namespace App\Modules\Event\Domain\Enums;

enum GameStatisticsModeEnum: string
{
    case SCORE_ONLY = 'score_only';
    case FULL = 'full';

    public function label(): string
    {
        return match ($this) {
            self::SCORE_ONLY => 'Только счёт',
            self::FULL => 'Полная статистика',
        };
    }
}
