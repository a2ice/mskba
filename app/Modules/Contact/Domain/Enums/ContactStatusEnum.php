<?php

namespace App\Modules\Contact\Domain\Enums;

enum ContactStatusEnum: string
{
    case VERIFIED = 'verified';
    case UNVERIFIED = 'unverified';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'Подтверждён',
            self::UNVERIFIED => 'Не подтверждён',
            self::BLOCKED => 'Заблокирован',
        };
    }
}