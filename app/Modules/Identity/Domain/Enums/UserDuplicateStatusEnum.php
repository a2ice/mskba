<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserDuplicateStatusEnum: string
{
    case PENDING = 'pending';
    case REJECTED = 'rejected';
    case MERGED = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'На проверке',
            self::REJECTED => 'Отклонён',
            self::MERGED => 'Объединён',
        };
    }
}
