<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserSystemRoleEnum: string
{
    case SUPERADMIN = 'superadmin';
    case ADMIN = 'admin';
    case USER = 'user';
    case MODERATOR = 'moderator';
    case EDITOR = 'editor';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Суперадмин',
            self::ADMIN => 'Админ',
            self::USER => 'Пользователь',
            self::MODERATOR => 'Модератор',
            self::EDITOR => 'Редактор',
            self::SYSTEM => 'Системный пользователь',
        };
    }

    public function numericValue(): int
    {
        return match ($this) {
            self::SUPERADMIN => 100,
            self::ADMIN => 80,
            self::MODERATOR => 60,
            self::EDITOR => 40,
            self::USER => 20,
            self::SYSTEM => 0,
        };
    }

    public function atLeast(UserSystemRoleEnum $atLeastRole): bool
    {
        return $this->numericValue() >= $atLeastRole->numericValue();
    }
}
