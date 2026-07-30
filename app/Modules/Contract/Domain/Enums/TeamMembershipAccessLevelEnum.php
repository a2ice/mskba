<?php

namespace App\Modules\Contract\Domain\Enums;

enum TeamMembershipAccessLevelEnum: string
{
    case OWNER = 'owner';
    case RESPONSIBLE = 'responsible';
    case CAPTAIN = 'captain';
    case COACH = 'coach';
    case PLAYER = 'player';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Владелец',
            self::RESPONSIBLE => 'Ответственный',
            self::CAPTAIN => 'Капитан',
            self::COACH => 'Тренер',
            self::PLAYER => 'Игрок',
        };
    }

    public function canManage(): bool
    {
        return in_array($this, [self::OWNER, self::RESPONSIBLE], true);
    }
}
