<?php

namespace App\Modules\Event\Domain\Enums;

enum GameFormatEnum: string
{
    case BASKETBALL_5X5 = 'basketball_5x5';
    case STREETBALL_3X3 = 'streetball_3x3';
    case STREETBALL_1X1 = 'streetball_1x1';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::BASKETBALL_5X5 => 'Баскетбол 5×5',
            self::STREETBALL_3X3 => 'Стритбол 3×3',
            self::STREETBALL_1X1 => 'Стритбол 1×1',
            self::CUSTOM => 'Свой формат',
        };
    }

    public function sideSize(): ?int
    {
        return match ($this) {
            self::BASKETBALL_5X5 => 5,
            self::STREETBALL_3X3 => 3,
            self::STREETBALL_1X1 => 1,
            self::CUSTOM => null,
        };
    }

    public function scoringType(): ?GameScoringTypeEnum
    {
        return match ($this) {
            self::BASKETBALL_5X5 => GameScoringTypeEnum::BASKETBALL,
            self::STREETBALL_3X3, self::STREETBALL_1X1 => GameScoringTypeEnum::STREETBALL,
            self::CUSTOM => null,
        };
    }
}
