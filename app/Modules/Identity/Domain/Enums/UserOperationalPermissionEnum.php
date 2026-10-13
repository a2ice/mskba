<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserOperationalPermissionEnum: string
{
    case CREATE_COORDINATION = 'coordination.create';
    case CREATE_TEAM = 'team.create';
    case CREATE_EVENT = 'event.create';
    case CREATE_TOURNAMENT = 'tournament.create';
    case MANAGE_SYSTEM_ROLES = 'user.system_role.manage';
    case REVIEW_PROFILE_CHANGES = 'identity.profile_change.review';
    case HANDLE_SUPPORT_TICKETS = 'support.ticket.handle';

    public function label(): string
    {
        return match ($this) {
            self::CREATE_COORDINATION => 'Создание опросов и согласований',
            self::CREATE_TEAM => 'Создание команд',
            self::CREATE_EVENT => 'Создание мероприятий',
            self::CREATE_TOURNAMENT => 'Создание турниров',
            self::MANAGE_SYSTEM_ROLES => 'Управление системными ролями',
            self::REVIEW_PROFILE_CHANGES => 'Рассматривать изменения подтверждённых данных',
            self::HANDLE_SUPPORT_TICKETS => 'Обрабатывать обращения в техподдержку',
        };
    }

    public function defaultAllowed(): bool
    {
        return match ($this) {
            self::CREATE_EVENT, self::CREATE_TOURNAMENT, self::MANAGE_SYSTEM_ROLES, self::REVIEW_PROFILE_CHANGES, self::HANDLE_SUPPORT_TICKETS => false,
            default => true,
        };
    }

    public function defaultAllowedFor(UserSystemRoleEnum $systemRole): bool
    {
        if (in_array($this, [self::MANAGE_SYSTEM_ROLES, self::REVIEW_PROFILE_CHANGES, self::HANDLE_SUPPORT_TICKETS], true)) {
            return $systemRole === UserSystemRoleEnum::SUPERADMIN;
        }

        return $systemRole->atLeast(UserSystemRoleEnum::ADMIN) || $this->defaultAllowed();
    }
}
