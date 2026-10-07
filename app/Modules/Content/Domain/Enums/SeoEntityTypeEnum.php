<?php

namespace App\Modules\Content\Domain\Enums;

enum SeoEntityTypeEnum: string
{
    case VENUE = 'venue';
    case EVENT = 'event';
    case TEAM = 'team';
    case TOURNAMENT = 'tournament';
    case SPORTS_SECTION = 'sports_section';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадки',
            self::EVENT => 'Мероприятия',
            self::TEAM => 'Команды',
            self::TOURNAMENT => 'Турниры',
            self::SPORTS_SECTION => 'Секции',
        };
    }
}
