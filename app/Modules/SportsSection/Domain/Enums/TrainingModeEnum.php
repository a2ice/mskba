<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum TrainingModeEnum: string
{
    case INDIVIDUAL = 'individual';
    case GROUP = 'group';

    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Индивидуально',
            self::GROUP => 'Групповой',
        };
    }
}
