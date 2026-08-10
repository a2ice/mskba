<?php

namespace App\Modules\Event\Domain\Enums;

enum GamePeriodsCountEnum: int
{
    case TWO = 2;
    case FOUR = 4;

    public function label(): string
    {
        return match ($this) {
            self::TWO => '2 периода',
            self::FOUR => '4 периода',
        };
    }
}
