<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamMemberTypeEnum: string
{
    case PLAYER = 'player';
    case COACH = 'coach';
    case MANAGER = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::PLAYER => 'Игрок',
            self::COACH => 'Тренер',
            self::MANAGER => 'Менеджер',
        };
    }
}
