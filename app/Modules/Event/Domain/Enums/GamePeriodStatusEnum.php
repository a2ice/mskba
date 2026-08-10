<?php

namespace App\Modules\Event\Domain\Enums;

enum GamePeriodStatusEnum: string
{
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Не начат',
            self::IN_PROGRESS => 'Идёт',
            self::COMPLETED => 'Завершён',
        };
    }
}
