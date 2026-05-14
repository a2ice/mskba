<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserRoleEnum: string
{
    case SUPERADMIN = 'superadmin';
    case ADMIN = 'admin';
    case USER = 'user';
    case MODERATOR = 'moderator';
    case EDITOR = 'editor';
    case GUEST = 'guest ';

    public function label(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Суперадмин',
            self::ADMIN => 'Админ',
            self::USER => 'Пользователь',
            self::MODERATOR => 'Модератор',
            self::EDITOR => 'Редактор',
            self::GUEST => 'Гость',
        };
    }

    // цифровые роли для логики вычисления доступа
    public function numericValue(): int
    {        
        return match ($this) {
            self::SUPERADMIN => 100,
            self::ADMIN => 80,
            self::MODERATOR => 60,
            self::EDITOR => 40,
            self::USER => 20,
            self::GUEST => 0,
        };
    }
}
