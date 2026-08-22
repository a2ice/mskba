<?php

namespace App\Modules\Team\Domain\Enums;

enum TeamPermissionEnum: string
{
    case EDIT_SETTINGS = 'team.settings.edit';
    case MANAGE_ROSTER = 'team.roster.manage';
    case INVITE_MEMBERS = 'team.members.invite';
    case MANAGE_JOIN_REQUESTS = 'team.join_requests.manage';
    case MANAGE_GAME_PARTICIPATION = 'team.game_participation.manage';
    case MANAGE_TOURNAMENT_PARTICIPATION = 'team.tournament_participation.manage';
    case MANAGE_ROLES = 'team.roles.manage';
    case MANAGE_PERMISSIONS = 'team.permissions.manage';
    case REMOVE_MEMBERS = 'team.members.remove';

    public function label(): string
    {
        return match ($this) {
            self::EDIT_SETTINGS => 'Редактировать настройки команды',
            self::MANAGE_ROSTER => 'Управлять основным составом и запасом',
            self::INVITE_MEMBERS => 'Приглашать участников',
            self::MANAGE_JOIN_REQUESTS => 'Управлять заявками на вступление',
            self::MANAGE_GAME_PARTICIPATION => 'Управлять участием команды в играх',
            self::MANAGE_TOURNAMENT_PARTICIPATION => 'Управлять участием команды в турнирах',
            self::MANAGE_ROLES => 'Назначать роли и капитана',
            self::MANAGE_PERMISSIONS => 'Выдавать права управления командой',
            self::REMOVE_MEMBERS => 'Исключать участников из команды',
        };
    }
}
