<?php

namespace App\Modules\Contact\Domain\Enums;

enum ContactVerificationChannelEnum: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case TELEGRAM = 'telegram';
    case VK = 'vk';
    case CALL = 'call';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::TELEGRAM => 'Telegram',
            self::VK => 'VK',
            self::CALL => 'Звонок',
            self::OTHER => 'Другое',
        };
    }
}
