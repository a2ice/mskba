<?php

namespace App\Modules\Identity\Domain\Enums\Participation;

enum PlayerPositionEnum: string
{
    case POINT_GUARD = 'point_guard';
    case SHOOTING_GUARD = 'shooting_guard';
    case SMALL_FORWARD = 'small_forward';
    case POWER_FORWARD = 'power_forward';
    case CENTER = 'center';

    public function label(): string
    {
        return match ($this) {
            self::POINT_GUARD => 'Разыгрывающий',
            self::SHOOTING_GUARD => 'Атакующий защитник',
            self::SMALL_FORWARD => 'Легкий форвард',
            self::POWER_FORWARD => 'Тяжелый форвард',
            self::CENTER => 'Центровой',
        };
    }
}
