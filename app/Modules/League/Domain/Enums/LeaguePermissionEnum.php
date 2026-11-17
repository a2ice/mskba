<?php

namespace App\Modules\League\Domain\Enums;

enum LeaguePermissionEnum: string
{
    case EDIT = 'league.edit';
    case MANAGE_TEAMS = 'league.teams.manage';
    case MANAGE_SEASONS = 'league.seasons.manage';
    case MANAGE_TOURNAMENTS = 'league.tournaments.manage';
    case MANAGE_CONTRACTS = 'league.contracts.manage';
    case ARCHIVE = 'league.archive';

    public function label(): string
    {
        return match ($this) {
            self::EDIT => 'Редактировать лигу',
            self::MANAGE_TEAMS => 'Управлять командами',
            self::MANAGE_SEASONS => 'Управлять сезонами',
            self::MANAGE_TOURNAMENTS => 'Управлять турнирами',
            self::MANAGE_CONTRACTS => 'Назначать контракты',
            self::ARCHIVE => 'Архивировать лигу',
        };
    }
}
