<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueDuplicateMatchTypeEnum: string
{
    case NAME = 'name';
    case ADDRESS = 'address';
    case NAME_AND_ADDRESS = 'name_and_address';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::NAME => 'Название',
            self::ADDRESS => 'Адрес',
            self::NAME_AND_ADDRESS => 'Название и адрес',
            self::MANUAL => 'Вручную',
        };
    }
}
