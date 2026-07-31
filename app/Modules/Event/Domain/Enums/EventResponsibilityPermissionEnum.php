<?php

namespace App\Modules\Event\Domain\Enums;

enum EventResponsibilityPermissionEnum: string
{
    case UPDATE_EVENT = 'event.update';
    case MANAGE_PARTICIPANTS = 'event.participants.manage';
    case MANAGE_RESPONSIBILITIES = 'event.responsibilities.manage';
    case MANAGE_RESULT = 'event.result.manage';
    case COMPLETE_EVENT = 'event.complete';
    case CANCEL_EVENT = 'event.cancel';
    case CREATE_MINI_GAME = 'mini_game.create';
    case UPDATE_MINI_GAME = 'mini_game.update';
    case MANAGE_MINI_GAME_ROSTER = 'mini_game.roster.manage';
    case MANAGE_MINI_GAME_SCORE = 'mini_game.score.manage';
    case MANAGE_MINI_GAME_STATISTICS = 'mini_game.statistics.manage';
    case COMPLETE_MINI_GAME = 'mini_game.complete';
    case DELETE_MINI_GAME = 'mini_game.delete';

    public function label(): string
    {
        return match ($this) {
            self::UPDATE_EVENT => 'Редактировать мероприятие',
            self::MANAGE_PARTICIPANTS => 'Управлять участниками',
            self::MANAGE_RESPONSIBILITIES => 'Назначать ответственных',
            self::MANAGE_RESULT => 'Редактировать итог и фотографии',
            self::COMPLETE_EVENT => 'Завершать мероприятие',
            self::CANCEL_EVENT => 'Отменять мероприятие',
            self::CREATE_MINI_GAME => 'Создавать мини-игры',
            self::UPDATE_MINI_GAME => 'Редактировать параметры мини-игр',
            self::MANAGE_MINI_GAME_ROSTER => 'Назначать состав мини-игр',
            self::MANAGE_MINI_GAME_SCORE => 'Вести счёт мини-игр',
            self::MANAGE_MINI_GAME_STATISTICS => 'Вести полную статистику',
            self::COMPLETE_MINI_GAME => 'Завершать мини-игры',
            self::DELETE_MINI_GAME => 'Удалять мини-игры',
        };
    }

    /** @return list<self> */
    public static function eventPermissions(): array
    {
        return [
            self::UPDATE_EVENT,
            self::MANAGE_PARTICIPANTS,
            self::MANAGE_RESPONSIBILITIES,
            self::MANAGE_RESULT,
            self::COMPLETE_EVENT,
            self::CANCEL_EVENT,
        ];
    }

    /** @return list<self> */
    public static function miniGamePermissions(): array
    {
        return [
            self::CREATE_MINI_GAME,
            self::UPDATE_MINI_GAME,
            self::MANAGE_MINI_GAME_ROSTER,
            self::MANAGE_MINI_GAME_SCORE,
            self::MANAGE_MINI_GAME_STATISTICS,
            self::COMPLETE_MINI_GAME,
            self::DELETE_MINI_GAME,
        ];
    }
}
