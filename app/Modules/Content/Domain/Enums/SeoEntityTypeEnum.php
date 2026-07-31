<?php

namespace App\Modules\Content\Domain\Enums;

enum SeoEntityTypeEnum: string
{
    case VENUE = 'venue';
    case EVENT = 'event';
    case TEAM = 'team';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадки',
            self::EVENT => 'Мероприятия',
            self::TEAM => 'Команды',
        };
    }
}
