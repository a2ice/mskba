<?php

namespace App\Modules\ContactVerification\Domain\Enums;

enum ContactVerificationPurposeEnum: string
{
    case SITE_CONTACT_FIRST = 'site_contact_first';
    case RESTORE_PASSWORD = 'restore_password';

    public function label(): string
    {
        return match ($this) {
            self::SITE_CONTACT_FIRST => 'Регистрация через контакт',
            self::RESTORE_PASSWORD => 'Восстановление пароля',
        };
    }
}
