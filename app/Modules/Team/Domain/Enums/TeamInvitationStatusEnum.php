<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamInvitationStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает подтверждения',
            self::ACCEPTED => 'Участник команды',
            self::DECLINED => 'Отклонено',
            self::REVOKED => 'Приглашение отозвано',
        };
    }
}
