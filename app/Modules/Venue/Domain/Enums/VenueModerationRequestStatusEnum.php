<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueModerationRequestStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'На рассмотрении',
            self::APPROVED => 'Подтверждена',
            self::REJECTED => 'Отклонена',
            self::BLOCKED => 'Заблокирована',
        };
    }
}
