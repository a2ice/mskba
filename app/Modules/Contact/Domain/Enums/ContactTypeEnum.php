<?php

namespace App\Modules\Contact\Domain\Enums;

enum ContactTypeEnum: string
{
    case EMAIL = 'email';
    case PHONE = 'phone';
    case TELEGRAM = 'telegram';
    case VK = 'vk';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::PHONE => 'Телефон',
            self::TELEGRAM => 'Telegram',
            self::VK => 'VK',
            self::OTHER => 'Другое',
        };
    }
}
