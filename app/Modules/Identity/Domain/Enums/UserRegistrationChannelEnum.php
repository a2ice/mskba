<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserRegistrationChannelEnum: string
{
    case SITE_EMAIL_FIRST = 'site_email_first';
    case SITE_FULL_REGISTRATION = 'site_full_registration';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SITE_EMAIL_FIRST => 'Регистрация через email',
            self::SITE_FULL_REGISTRATION => 'Полная регистрация',
            self::OTHER => 'Другое',
        };
    }
}
