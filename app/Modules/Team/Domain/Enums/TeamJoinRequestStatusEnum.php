<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamJoinRequestStatusEnum: string
{
    case PENDING = 'pending';
    case AWAITING_RESPONSE = 'awaiting_response';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает решения',
            self::AWAITING_RESPONSE => 'Ожидается ответ кандидата',
            self::ACCEPTED => 'Принята',
            self::REJECTED => 'Отклонена',
            self::BLOCKED => 'Заблокирована',
        };
    }
}
