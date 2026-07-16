<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueStatusEnum: string
{
    case UNCONFIRMED = 'unconfirmed';
    case CONFIRMED = 'confirmed';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::UNCONFIRMED => 'Не подтверждён',
            self::CONFIRMED => 'Подтверждён',
            self::BLOCKED => 'Заблокирован',
        };
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::CONFIRMED;
    }
}
