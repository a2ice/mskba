<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentPermissionEnum: string
{
    case MANAGE_GAMES = 'manage_tournament_games';
    case MANAGE_STATUS = 'manage_tournament_status';
    case MANAGE_DESCRIPTION = 'manage_tournament_description';
    case MANAGE_STAFF = 'manage_tournament_staff';
    case DELETE = 'delete_tournament';

    public function label(): string
    {
        return match ($this) {
            self::MANAGE_GAMES => 'Управлять играми',
            self::MANAGE_STATUS => 'Менять статус',
            self::MANAGE_DESCRIPTION => 'Редактировать описание и обложку',
            self::MANAGE_STAFF => 'Управлять ответственными',
            self::DELETE => 'Удалять турнир',
        };
    }
}
