<?php

namespace App\Modules\Event\Domain\Enums;

enum EventParticipantRoleEnum: string
{
    case ORGANIZER = 'organizer';
    case PARTICIPANT = 'participant';

    public function label(): string
    {
        return match ($this) {
            self::ORGANIZER => 'Организатор',
            self::PARTICIPANT => 'Участник',
        };
    }
}
