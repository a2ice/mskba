<?php

namespace App\Modules\Event\Domain\Enums;

enum GameStatusEnum: string
{
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case AWAITING_RESULT = 'awaiting_result';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Запланирована',
            self::IN_PROGRESS => 'Идёт',
            self::AWAITING_RESULT => 'Ожидает результата',
            self::COMPLETED => 'Завершена',
            self::CANCELLED => 'Отменена',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
