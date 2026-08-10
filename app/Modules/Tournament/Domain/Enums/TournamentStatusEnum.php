<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentStatusEnum: string
{
    case CONFIRMED = 'confirmed';
    case UNCONFIRMED = 'unconfirmed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Подтверждён',
            self::UNCONFIRMED => 'Не подтверждён',
            self::CANCELLED => 'Отменён',
        };
    }
}
