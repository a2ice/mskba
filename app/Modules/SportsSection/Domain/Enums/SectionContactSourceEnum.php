<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SectionContactSourceEnum: string
{
    case HEAD_COACH = 'head_coach';
    case SECTION = 'section';

    public function label(): string
    {
        return match ($this) {
            self::HEAD_COACH => 'Контакты главного тренера', self::SECTION => 'Контакты секции'
        };
    }
}
