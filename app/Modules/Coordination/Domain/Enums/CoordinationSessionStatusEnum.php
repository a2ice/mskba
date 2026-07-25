<?php

namespace App\Modules\Coordination\Domain\Enums;

enum CoordinationSessionStatusEnum: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case DECISION_PENDING = 'decision_pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::OPEN => 'Идёт голосование',
            self::DECISION_PENDING => 'Ожидает решения',
            self::COMPLETED => 'Решение принято',
            self::CANCELLED => 'Отменено',
        };
    }
}
