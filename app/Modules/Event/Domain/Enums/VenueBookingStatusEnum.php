<?php

namespace App\Modules\Event\Domain\Enums;

enum VenueBookingStatusEnum: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::CONFIRMED => 'Подтверждено',
            self::CANCELLED => 'Отменено',
        };
    }

    public function occupiesVenue(): bool
    {
        return $this !== self::CANCELLED;
    }
}
