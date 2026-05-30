<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractTypesEnum: string
{
    case VENUE = 'venue';
    case SERVICE = 'service';
    case EVENT = 'event';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Договор площадки',
            self::SERVICE => 'Договор на оказание услуг',
            self::EVENT => 'Договор на проведение мероприятия',
        };
    }
}