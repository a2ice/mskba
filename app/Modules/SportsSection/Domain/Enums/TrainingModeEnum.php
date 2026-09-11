<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum TrainingModeEnum: string
{
    case INDIVIDUAL = 'individual';
    case SMALL_GROUP = 'small_group';
    case TEAM = 'team';

    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Индивидуально', self::SMALL_GROUP => 'Малая группа', self::TEAM => 'Команда',
        };
    }
}
