<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SportsSectionJoinRequestStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'На рассмотрении',
            self::ACCEPTED => 'Принята',
            self::REJECTED => 'Отклонена',
            self::CANCELLED => 'Отменена',
        };
    }
}
