<?php

namespace App\Modules\Coordination\Domain\Enums;

enum ParticipationIntentEnum: string
{
    case GOING = 'going';
    case NOT_GOING = 'not_going';
    case THINKING = 'thinking';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GOING => 'Положительное решение',
            self::NOT_GOING => 'Отрицательное решение',
            self::THINKING => 'Раздумывает',
            self::CUSTOM => 'Свой вариант',
        };
    }
}
