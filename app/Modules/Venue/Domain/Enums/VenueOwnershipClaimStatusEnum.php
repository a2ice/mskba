<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOwnershipClaimStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PENDING => 'На рассмотрении',
            self::APPROVED => 'Одобрена',
            self::REJECTED => 'Отклонена',
            self::CANCELLED => 'Отменена',
        };
    }
}
