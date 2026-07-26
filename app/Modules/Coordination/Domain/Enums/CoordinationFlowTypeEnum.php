<?php

namespace App\Modules\Coordination\Domain\Enums;

enum CoordinationFlowTypeEnum: string
{
    case SINGLE = 'single';
    case EVENT_SCHEDULING = 'event_scheduling';
    case EVENT_ATTENDANCE = 'event_attendance';
    case EVENT_TIME_SELECTION = 'event_time_selection';
    case EVENT_VENUE_SELECTION = 'event_venue_selection';
    case EVENT_CHANGE = 'event_change';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Один вопрос',
            self::EVENT_SCHEDULING => 'Дата, время и площадка',
            self::EVENT_ATTENDANCE => 'Собрать участников',
            self::EVENT_TIME_SELECTION => 'Выбрать время',
            self::EVENT_VENUE_SELECTION => 'Выбрать площадку',
            self::EVENT_CHANGE => 'Изменение мероприятия',
        };
    }
}
