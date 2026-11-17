<?php

namespace App\Modules\Identity\Domain\Enums\Participation;

enum PlayerBodyTypeEnum: string
{
    case SLIM = 'slim';
    case NORMAL = 'normal';
    case LARGE = 'large';
    case ATHLETIC = 'athletic';
    case MUSCULAR = 'muscular';
    case STOCKY = 'stocky';

    public function label(): string
    {
        return match ($this) {
            self::SLIM => 'Худощавое',
            self::NORMAL => 'Нормальное',
            self::ATHLETIC => 'Атлетичное',
            self::MUSCULAR => 'Мускулистое',
            self::STOCKY => 'Коренастое',
            self::LARGE => 'Упитанное',
        };
    }
}
