<?php

namespace App\Modules\Moderation\Domain\Enums;

enum ModerationRequestStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'На рассмотрении',
            self::APPROVED => 'Подтверждена',
            self::REJECTED => 'Отклонена',
        };
    }
}
