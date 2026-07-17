<?php

namespace App\Modules\Moderation\Domain\Enums;

enum ModerationTypeEnum: string
{
    case VENUE = 'venue';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадка',
        };
    }
}
