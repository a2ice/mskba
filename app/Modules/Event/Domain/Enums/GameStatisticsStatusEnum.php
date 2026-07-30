<?php

namespace App\Modules\Event\Domain\Enums;

enum GameStatisticsStatusEnum: string
{
    case NOT_STARTED = 'not_started';
    case ENTERING = 'entering';
    case READY = 'ready';
    case CONFIRMED = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Не заполнена',
            self::ENTERING => 'Заполняется',
            self::READY => 'Готова к проверке',
            self::CONFIRMED => 'Подтверждена',
        };
    }
}
