<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentPhaseEnum: string
{
    case UPCOMING = 'upcoming';
    case ONGOING = 'ongoing';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::UPCOMING => 'Предстоящий',
            self::ONGOING => 'Идёт',
            self::COMPLETED => 'Завершён',
            self::CANCELLED => 'Отменён',
        };
    }
}
