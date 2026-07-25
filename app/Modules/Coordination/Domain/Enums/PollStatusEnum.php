<?php

namespace App\Modules\Coordination\Domain\Enums;

enum PollStatusEnum: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::OPEN => 'Открыт',
            self::CLOSED => 'Закрыт',
            self::CANCELLED => 'Отменён',
        };
    }
}
