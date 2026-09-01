<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamHiringStatusEnum: string
{
    case ACTIVE = 'active';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Идёт набор',
            self::CLOSED => 'Набор закрыт',
        };
    }
}
