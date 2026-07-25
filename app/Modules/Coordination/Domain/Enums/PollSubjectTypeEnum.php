<?php

namespace App\Modules\Coordination\Domain\Enums;

enum PollSubjectTypeEnum: string
{
    case TEXT = 'text';
    case PARTICIPATION = 'participation';
    case DATE = 'date';
    case TIME = 'time';
    case DATETIME = 'datetime';
    case TIME_INTERVAL = 'time_interval';
    case VENUE = 'venue';

    public function label(): string
    {
        return match ($this) {
            self::TEXT => 'Текстовый выбор',
            self::PARTICIPATION => 'Участие',
            self::DATE => 'Дата',
            self::TIME => 'Время',
            self::DATETIME => 'Дата и время',
            self::TIME_INTERVAL => 'Интервал времени',
            self::VENUE => 'Площадка',
        };
    }
}
