<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamSportTypeEnum: string
{
    case STREETBALL = 'streetball';
    case BASKETBALL = 'basketball';

    public function label(): string
    {
        return match ($this) {
            self::STREETBALL => 'Стритбол',
            self::BASKETBALL => 'Баскетбол',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::STREETBALL => '3x3',
            self::BASKETBALL => '5x5',
        };
    }
}
