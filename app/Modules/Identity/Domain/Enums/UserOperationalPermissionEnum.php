<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserOperationalPermissionEnum: string
{
    case CREATE_COORDINATION = 'coordination.create';

    public function label(): string
    {
        return match ($this) {
            self::CREATE_COORDINATION => 'Создание опросов и согласований',
        };
    }
}
