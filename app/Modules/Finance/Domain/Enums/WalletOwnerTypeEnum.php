<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletOwnerTypeEnum: string
{
    case USER = 'user';
    case TEAM = 'team';
    case EVENT = 'event';
    case VENUE = 'venue';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Пользователь',
            self::TEAM => 'Команда',
            self::EVENT => 'Мероприятие',
            self::VENUE => 'Площадка',
        };
    }
}
