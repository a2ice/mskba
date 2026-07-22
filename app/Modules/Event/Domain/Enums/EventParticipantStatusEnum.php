<?php

namespace App\Modules\Event\Domain\Enums;

enum EventParticipantStatusEnum: string
{
    case CONFIRMED = 'confirmed';
    case LEFT = 'left';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Участвует',
            self::LEFT => 'Отказался',
        };
    }
}
