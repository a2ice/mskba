<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserPrivacyVisibilityEnum: string
{
    case EVERYONE = 'everyone';
    case SELECTED_USERS = 'selected_users';
    case NOBODY = 'nobody';

    public function label(): string
    {
        return match ($this) {
            self::EVERYONE => 'Всем',
            self::SELECTED_USERS => 'Определённым пользователям',
            self::NOBODY => 'Никому',
        };
    }
}
