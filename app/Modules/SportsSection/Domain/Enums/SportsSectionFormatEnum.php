<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SportsSectionFormatEnum: string
{
    case BASKETBALL = 'basketball';
    case STREETBALL = 'streetball';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BASKETBALL => 'Баскетбол',
            self::STREETBALL => 'Стритбол',
            self::OTHER => 'Другое',
        };
    }
}
