<?php

namespace App\Modules\ContactVerification\Domain\Enums;

enum ContactVerificationStatusEnum: string
{
    case PENDING = 'pending';
    case VERIFIED = 'verified';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::VERIFIED => 'Подтверждено',
            self::EXPIRED => 'Истекло',
            self::FAILED => 'Не удалось',
            self::CANCELLED => 'Отменено',
        };
    }
}
