<?php

namespace App\Modules\Notification\Domain\Enums;

enum SupportClosureReasonEnum: string
{
    case RESOLVED = 'resolved';
    case NO_LONGER_RELEVANT = 'no_longer_relevant';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RESOLVED => 'Вопрос решён',
            self::NO_LONGER_RELEVANT => 'Не актуально',
            self::OTHER => 'Другая причина',
        };
    }
}
