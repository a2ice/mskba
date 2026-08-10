<?php

namespace App\Modules\Event\Domain\Enums;

enum GameActionTypeEnum: string
{
    case SHOT_MADE = 'shot_made';
    case SHOT_MISSED = 'shot_missed';
    case ASSIST = 'assist';
    case REBOUND = 'rebound';
    case STEAL = 'steal';
    case FOUL = 'foul';
    case STATISTICS_CORRECTION = 'statistics_correction';
    case SCORE_CORRECTION = 'score_correction';

    public function label(): string
    {
        return match ($this) {
            self::SHOT_MADE => 'Попадание',
            self::SHOT_MISSED => 'Промах',
            self::ASSIST => 'Передача',
            self::REBOUND => 'Подбор',
            self::STEAL => 'Перехват',
            self::FOUL => 'Фол',
            self::STATISTICS_CORRECTION => 'Коррекция статистики',
            self::SCORE_CORRECTION => 'Коррекция счёта',
        };
    }
}
