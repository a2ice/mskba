<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOwnershipClaimStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'На рассмотрении',
            self::APPROVED => 'Одобрена',
            self::REJECTED => 'Отклонена',
            self::CANCELLED => 'Отменена',
        };
    }
}
