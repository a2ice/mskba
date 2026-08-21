<?php

namespace App\Modules\Event\Domain\Enums;

enum GameAdmissionStatusEnum: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case REVOKED = 'revoked';

    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::ACCEPTED], true);
    }
}
