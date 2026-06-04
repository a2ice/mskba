<?php

namespace App\Modules\Contact\Domain\Enums;

enum ContactVerificationStatusEnum: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::CONFIRMED => 'Подтверждено',
            self::EXPIRED => 'Истекло',
            self::FAILED => 'Ошибка',
            self::CANCELLED => 'Отменено',
        };
    }
}
