<?php

namespace App\Modules\Identity\Domain\Enums;

enum UserParticipationRoleEnum: string
{
    case PLAYER = 'player';
    case COACH = 'coach';
    case REFEREE = 'referee';
    case STATISTICIAN = 'statistician';
    case MEDIA = 'media';
    case VENUE_RELATED = 'venue_related';

    public function label(): string
    {
        return match ($this) {
            self::PLAYER => 'Игрок',
            self::COACH => 'Тренер',
            self::REFEREE => 'Судья',
            self::STATISTICIAN => 'Статист',
            self::MEDIA => 'Медиа',
            self::VENUE_RELATED => 'Представитель площадки',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PLAYER => 'Играю в баскетбол и участвую в играх и тренировках.',
            self::COACH => 'Провожу тренировки и помогаю игрокам развиваться.',
            self::REFEREE => 'Сужу матчи и другие баскетбольные мероприятия.',
            self::STATISTICIAN => 'Веду статистику игр, команд и игроков.',
            self::MEDIA => 'Создаю фото, видео и другие материалы о баскетболе.',
            self::VENUE_RELATED => 'Представляю площадку или участвую в её работе.',
        };
    }

    public function isConfirmationRequired(): bool
    {
        return match ($this) {
            self::PLAYER, self::COACH, self::REFEREE => true,
            self::STATISTICIAN, self::MEDIA, self::VENUE_RELATED => false,
        };
    }
}
