<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueDuplicateStatusEnum: string
{
    case PENDING = 'pending';
    case MERGED = 'merged';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает решения',
            self::MERGED => 'Объединён',
            self::REJECTED => 'Отклонён',
        };
    }
}
