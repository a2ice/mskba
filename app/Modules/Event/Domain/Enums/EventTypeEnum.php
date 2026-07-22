<?php

namespace App\Modules\Event\Domain\Enums;

enum EventTypeEnum: string
{
    case GAME = 'game';
    case TRAINING = 'training';
    case GAME_TRAINING = 'game_training';
    case TOURNAMENT = 'tournament';

    public function label(): string
    {
        return match ($this) {
            self::GAME => 'Игра',
            self::TRAINING => 'Тренировка',
            self::GAME_TRAINING => 'Игровая тренировка',
            self::TOURNAMENT => 'Турнир',
        };
    }

    public function createLabel(): string
    {
        return match ($this) {
            self::GAME => 'Создать игру',
            self::TRAINING => 'Создать тренировку',
            self::GAME_TRAINING => 'Создать игровую тренировку',
            self::TOURNAMENT => 'Создать турнир',
        };
    }

    public function newLabel(): string
    {
        return match ($this) {
            self::GAME => 'Новая игра',
            self::TRAINING => 'Новая тренировка',
            self::GAME_TRAINING => 'Новая игровая тренировка',
            self::TOURNAMENT => 'Новый турнир',
        };
    }
}
