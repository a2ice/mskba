<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationTypeEnum: string
{
    case SYSTEM = 'system';
    case SECURITY = 'security';
    case PROFILE = 'profile';
    case REMINDER = 'reminder';
    case FINANCE = 'finance';
    case MESSAGE = 'message';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => 'Системное',
            self::SECURITY => 'Безопасность',
            self::PROFILE => 'Профиль',
            self::REMINDER => 'Напоминание',
            self::FINANCE => 'Финансы',
            self::MESSAGE => 'Сообщение',
        };
    }

    /** Stable notification palette shared by the toast UI and future messages UI. */
    public function toastBorderColor(): string
    {
        return match ($this) {
            self::SYSTEM => '#ff8a00',
            self::SECURITY => '#ef4444',
            self::PROFILE => '#a78bfa',
            self::REMINDER => '#facc15',
            self::FINANCE => '#22c55e',
            self::MESSAGE => '#38bdf8',
        };
    }
}
