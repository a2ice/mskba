<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserOperationalPermissionEnum: string
{
    case CREATE_COORDINATION = 'coordination.create';
    case CREATE_TEAM = 'team.create';
    case CREATE_EVENT = 'event.create';
    case CREATE_TOURNAMENT = 'tournament.create';
    case MANAGE_SYSTEM_ROLES = 'user.system_role.manage';

    public function label(): string
    {
        return match ($this) {
            self::CREATE_COORDINATION => 'Создание опросов и согласований',
            self::CREATE_TEAM => 'Создание команд',
            self::CREATE_EVENT => 'Создание мероприятий',
            self::CREATE_TOURNAMENT => 'Создание турниров',
            self::MANAGE_SYSTEM_ROLES => 'Управление системными ролями',
        };
    }

    public function defaultAllowed(): bool
    {
        return match ($this) {
            self::CREATE_EVENT, self::CREATE_TOURNAMENT, self::MANAGE_SYSTEM_ROLES => false,
            default => true,
        };
    }

    public function defaultAllowedFor(UserSystemRoleEnum $systemRole): bool
    {
        if ($this === self::MANAGE_SYSTEM_ROLES) {
            return $systemRole === UserSystemRoleEnum::SUPERADMIN;
        }

        return $systemRole->atLeast(UserSystemRoleEnum::ADMIN) || $this->defaultAllowed();
    }
}
