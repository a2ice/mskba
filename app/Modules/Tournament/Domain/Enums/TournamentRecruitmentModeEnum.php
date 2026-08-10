<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentRecruitmentModeEnum: string
{
    case PREFORMED_TEAMS = 'preformed_teams';
    case INDIVIDUAL_DRAFT = 'individual_draft';

    public function label(): string
    {
        return match ($this) {
            self::PREFORMED_TEAMS => 'Готовые команды',
            self::INDIVIDUAL_DRAFT => 'Отдельные игроки с balanced-формированием',
        };
    }
}
