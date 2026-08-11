<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAdmissionRoleEnum: string
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
