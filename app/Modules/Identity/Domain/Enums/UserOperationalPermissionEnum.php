<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserOperationalPermissionEnum: string
{
    case CREATE_COORDINATION = 'coordination.create';
    case CREATE_TEAM = 'team.create';
    case CREATE_EVENT = 'event.create';
    case CREATE_TOURNAMENT = 'tournament.create';

    public function label(): string
    {
        return match ($this) {
            self::CREATE_COORDINATION => 'Создание опросов и согласований',
            self::CREATE_TEAM => 'Создание команд',
            self::CREATE_EVENT => 'Создание мероприятий',
            self::CREATE_TOURNAMENT => 'Создание турниров',
        };
    }

    public function defaultAllowed(): bool
    {
        return match ($this) {
            self::CREATE_EVENT, self::CREATE_TOURNAMENT => false,
            default => true,
        };
    }
}
