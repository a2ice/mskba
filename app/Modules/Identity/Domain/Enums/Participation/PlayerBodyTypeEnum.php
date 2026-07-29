<?php

namespace App\Modules\Identity\Domain\Enums\Participation;

enum PlayerBodyTypeEnum: string
{
    case SLIM = 'slim';
    case ATHLETIC = 'athletic';
    case MUSCULAR = 'muscular';
    case STOCKY = 'stocky';
    case LARGE = 'large';

    public function label(): string
    {
        return match ($this) {
            self::SLIM => 'Худощавое',
            self::ATHLETIC => 'Атлетичное',
            self::MUSCULAR => 'Мускулистое',
            self::STOCKY => 'Коренастое',
            self::LARGE => 'Крупное',
        };
    }
}
