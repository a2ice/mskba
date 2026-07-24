<?php

namespace App\Modules\Event\Domain\Enums;

enum EventParticipantStatusEnum: string
{
    case CONFIRMED = 'confirmed';
    case TENTATIVE = 'tentative';
    case LEFT = 'left';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => 'Участвует',
            self::TENTATIVE => 'Под вопросом',
            self::LEFT => 'Отказался',
        };
    }
}
