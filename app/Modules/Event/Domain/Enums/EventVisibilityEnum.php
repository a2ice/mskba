<?php

namespace App\Modules\Event\Domain\Enums;

enum EventVisibilityEnum: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC => 'Публичное',
            self::PRIVATE => 'По приглашению',
        };
    }
}
