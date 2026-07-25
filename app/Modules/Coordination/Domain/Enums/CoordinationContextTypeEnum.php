<?php

namespace App\Modules\Coordination\Domain\Enums;

enum CoordinationContextTypeEnum: string
{
    case EVENT = 'event';
    case VENUE = 'venue';
    case TEAM = 'team';
    case COMMUNITY = 'community';
    case CHAT = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::EVENT => 'Мероприятие',
            self::VENUE => 'Площадка',
            self::TEAM => 'Команда',
            self::COMMUNITY => 'Сообщество',
            self::CHAT => 'Чат',
        };
    }
}
