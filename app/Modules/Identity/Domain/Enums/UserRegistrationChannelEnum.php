<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserRegistrationChannelEnum: string
{
    case SITE_CONTACT_FIRST = 'site_contact_first';
    case SITE_FULL_REGISTRATION = 'site_full_registration';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SITE_CONTACT_FIRST => 'Регистрация через контакт',
            self::SITE_FULL_REGISTRATION => 'Полная регистрация',
            self::OTHER => 'Другое',
        };
    }
}
