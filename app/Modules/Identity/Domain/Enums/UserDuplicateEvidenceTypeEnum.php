<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserDuplicateEvidenceTypeEnum: string
{
    case TELEGRAM_IDENTITY = 'telegram_identity';
    case VERIFIED_EMAIL = 'verified_email';
    case VERIFIED_PHONE = 'verified_phone';
    case PROFILE_IDENTITY = 'profile_identity';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::TELEGRAM_IDENTITY => 'Telegram identity',
            self::VERIFIED_EMAIL => 'Подтверждённый email',
            self::VERIFIED_PHONE => 'Подтверждённый телефон',
            self::PROFILE_IDENTITY => 'Совпадение профиля',
            self::MANUAL => 'Ручная проверка',
        };
    }

    public function defaultScore(): int
    {
        return match ($this) {
            self::TELEGRAM_IDENTITY => 100,
            self::VERIFIED_EMAIL => 95,
            self::VERIFIED_PHONE => 90,
            self::PROFILE_IDENTITY => 65,
            self::MANUAL => 50,
        };
    }
}
