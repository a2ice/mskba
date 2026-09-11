<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum TraineeMembershipStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активен', self::INACTIVE => 'Неактивен'
        };
    }
}
