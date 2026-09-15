<?php

namespace App\Modules\Acquisition\Domain\Enums;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;

enum AcquisitionPersonaEnum: string
{
    case PLAYER = 'player';
    case COACH = 'coach';
    case VENUE = 'venue';
    case ORGANIZER = 'organizer';
    case EXPLORE = 'explore';

    public function label(): string
    {
        return match ($this) {
            self::PLAYER => 'Игрок',
            self::COACH => 'Тренер',
            self::VENUE => 'Представитель площадки',
            self::ORGANIZER => 'Организатор мероприятий',
            self::EXPLORE => 'Пока просто посмотрю',
        };
    }

    public function registrationRole(): ?UserParticipationRoleEnum
    {
        return match ($this) {
            self::PLAYER => UserParticipationRoleEnum::PLAYER,
            self::COACH => UserParticipationRoleEnum::COACH,
            self::VENUE => UserParticipationRoleEnum::VENUE_RELATED,
            self::ORGANIZER, self::EXPLORE => null,
        };
    }

    public function needsProfileDetails(): bool
    {
        return in_array($this, [self::PLAYER, self::COACH], true);
    }
}
