<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::ACTIVE => 'Активна',
            self::BLOCKED => 'Заблокирована',
            self::ARCHIVED => 'В архиве',
        };
    }
}
