<?php

namespace App\Modules\Coordination\Domain\Enums;

enum PollResultsVisibilityEnum: string
{
    case ALWAYS = 'always';
    case AFTER_VOTE = 'after_vote';
    case AFTER_CLOSE = 'after_close';

    public function label(): string
    {
        return match ($this) {
            self::ALWAYS => 'Всегда',
            self::AFTER_VOTE => 'После голоса',
            self::AFTER_CLOSE => 'После закрытия',
        };
    }
}
