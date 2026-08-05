<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamJoinRequestStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает решения',
            self::ACCEPTED => 'Принята',
            self::REJECTED => 'Отклонена',
            self::BLOCKED => 'Заблокирована',
        };
    }
}
