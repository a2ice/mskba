<?php

namespace App\Modules\League\Domain\Enums;

enum SeasonPermissionEnum: string
{
    case EDIT = 'season.edit';
    case MANAGE_TEAMS = 'season.teams.manage';
    case MANAGE_TOURNAMENTS = 'season.tournaments.manage';
    case MANAGE_CONTRACTS = 'season.contracts.manage';
    case ARCHIVE = 'season.archive';

    public function label(): string
    {
        return match ($this) {
            self::EDIT => 'Редактировать сезон',
            self::MANAGE_TEAMS => 'Управлять командами',
            self::MANAGE_TOURNAMENTS => 'Управлять турнирами',
            self::MANAGE_CONTRACTS => 'Назначать контракты',
            self::ARCHIVE => 'Архивировать сезон',
        };
    }
}
