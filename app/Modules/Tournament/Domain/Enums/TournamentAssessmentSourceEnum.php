<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAssessmentSourceEnum: string
{
    case SELF_ASSESSMENT = 'self_assessment';
    case OBJECTIVE_ASSESSMENT = 'objective_assessment';

    public function label(): string
    {
        return match ($this) {
            self::SELF_ASSESSMENT => 'Учитывать самооценку',
            self::OBJECTIVE_ASSESSMENT => 'Учитывать объективную статистику',
        };
    }
}
