<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationTypeEnum: string
{
    case SYSTEM = 'system';
    case SECURITY = 'security';
    case PROFILE = 'profile';
    case REMINDER = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => 'Системное',
            self::SECURITY => 'Безопасность',
            self::PROFILE => 'Профиль',
            self::REMINDER => 'Напоминание',
        };
    }
}
