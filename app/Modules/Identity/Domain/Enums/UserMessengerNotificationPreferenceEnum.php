<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserMessengerNotificationPreferenceEnum: string
{
    case ALL = 'all';
    case SYSTEM_AND_REQUESTS = 'system_and_requests';
    case SYSTEM_ONLY = 'system_only';
    case REQUESTS_ONLY = 'requests_only';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Все уведомления',
            self::SYSTEM_AND_REQUESTS => 'Системные и запросы',
            self::SYSTEM_ONLY => 'Только системные',
            self::REQUESTS_ONLY => 'Только запросы',
            self::NONE => 'Не отправлять',
        };
    }
}
