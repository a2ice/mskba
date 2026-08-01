<?php

namespace App\Modules\Event\Domain\Enums;

enum GameScoringTypeEnum: string
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
}
