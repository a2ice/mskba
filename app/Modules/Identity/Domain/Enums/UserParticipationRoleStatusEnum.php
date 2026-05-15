<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserParticipationRoleStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активна',
            self::INACTIVE => 'Неактивна',
        };
    }
}
