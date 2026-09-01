<?php

namespace App\Modules\Event\Domain\Enums;

enum VenueBookingStatusEnum: string
{
    /** Legacy Event-first status. It keeps occupying the venue during rollout. */
    case PENDING = 'pending';
    case REQUESTED = 'requested';
    case HELD = 'held';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::REQUESTED => 'Заявка отправлена',
            self::HELD => 'Слот удерживается',
            self::CONFIRMED => 'Подтверждено',
            self::REJECTED => 'Отклонено',
            self::CANCELLED => 'Отменено',
            self::EXPIRED => 'Истекло',
        };
    }

    public function occupiesVenue(): bool
    {
        return in_array($this->value, self::occupyingValues(), true);
    }

    /** @return list<string> */
    public static function occupyingValues(): array
    {
        return [self::PENDING->value, self::HELD->value, self::CONFIRMED->value];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::REJECTED, self::CANCELLED, self::EXPIRED], true);
    }
}
