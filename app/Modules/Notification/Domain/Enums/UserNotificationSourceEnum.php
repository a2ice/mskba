<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationSourceEnum: string
{
    case IDENTITY_REGISTRATION = 'identity.registration';
    case CONTACT_CONFIRMATION = 'contact.confirmation';

    public function label(): string
    {
        return match ($this) {
            self::IDENTITY_REGISTRATION => 'Регистрация пользователя',
            self::CONTACT_CONFIRMATION => 'Подтверждение контакта',
        };
    }
}
