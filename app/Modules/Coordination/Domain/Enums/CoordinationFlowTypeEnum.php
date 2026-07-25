<?php

namespace App\Modules\Coordination\Domain\Enums;

enum CoordinationFlowTypeEnum: string
{
    case SINGLE = 'single';
    case EVENT_SCHEDULING = 'event_scheduling';
    case EVENT_CHANGE = 'event_change';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Один вопрос',
            self::EVENT_SCHEDULING => 'Дата, время и площадка',
            self::EVENT_CHANGE => 'Изменение мероприятия',
        };
    }
}
