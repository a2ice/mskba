<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamPermissionEnum: string
{
    case MANAGE_ROSTER = 'team.roster.manage';
    case INVITE_MEMBERS = 'team.members.invite';
    case MANAGE_ROLES = 'team.roles.manage';
    case MANAGE_PERMISSIONS = 'team.permissions.manage';
    case REMOVE_MEMBERS = 'team.members.remove';

    public function label(): string
    {
        return match ($this) {
            self::MANAGE_ROSTER => 'Управлять основным составом и запасом',
            self::INVITE_MEMBERS => 'Приглашать участников',
            self::MANAGE_ROLES => 'Назначать роли и капитана',
            self::MANAGE_PERMISSIONS => 'Выдавать права управления командой',
            self::REMOVE_MEMBERS => 'Исключать участников из команды',
        };
    }
}
