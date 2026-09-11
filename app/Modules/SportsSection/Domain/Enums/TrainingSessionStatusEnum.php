<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum TrainingSessionStatusEnum: string
{
    case PLANNED = 'planned';
    case CONFIRMED = 'confirmed';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Запланировано', self::CONFIRMED => 'Подтверждено', self::COMPLETED => 'Завершено', self::CANCELLED => 'Отменено',
        };
    }
}
