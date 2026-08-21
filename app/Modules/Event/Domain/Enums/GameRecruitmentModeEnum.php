<?php

namespace App\Modules\Event\Domain\Enums;

enum GameRecruitmentModeEnum: string
{
    case PREFORMED_TEAMS = 'preformed_teams';
    case INDIVIDUAL_DRAFT = 'individual_draft';

    public function label(): string
    {
        return match ($this) {
            self::PREFORMED_TEAMS => 'Готовые команды',
            self::INDIVIDUAL_DRAFT => 'Отдельные игроки',
        };
    }
}
