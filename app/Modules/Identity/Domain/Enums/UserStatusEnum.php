<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserStatusEnum: string
{
    case UNCONFIRMED = 'unconfirmed';
    case CONFIRMED = 'confirmed';
    case BLOCKED = 'blocked';
    case REMOVED = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::UNCONFIRMED => 'Не подтверждён',
            self::CONFIRMED => 'Подтверждён',
            self::BLOCKED => 'Заблокирован',
            self::REMOVED => 'Удалён',
        };
    }
}
