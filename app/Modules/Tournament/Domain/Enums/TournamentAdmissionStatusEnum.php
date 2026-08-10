<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAdmissionStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает ответа',
            self::ACCEPTED => 'Принято',
            self::DECLINED => 'Отклонено',
            self::REVOKED => 'Отозвано',
        };
    }
}
