<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserGenderEnum: string
{
    case MALE = 'male';
    case FEMALE = 'female';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Мужской',
            self::FEMALE => 'Женский',
        };
    }
}
