<?php

namespace App\Modules\Event\Domain\Enums;

enum EventResponsibilityStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::ACCEPTED => 'Ответственный',
            self::DECLINED => 'Назначение отклонено',
        };
    }
}
