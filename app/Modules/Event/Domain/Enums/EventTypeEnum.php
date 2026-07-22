<?php

namespace App\Modules\Event\Domain\Enums;

enum EventTypeEnum: string
{
    case GAME = 'game';
    case TRAINING = 'training';
    case TOURNAMENT = 'tournament';

    public function label(): string
    {
        return match ($this) {
            self::GAME => 'Игра',
            self::TRAINING => 'Тренировка',
            self::TOURNAMENT => 'Турнир',
        };
    }
}
