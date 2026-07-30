<?php

namespace App\Modules\Event\Domain\Enums;

enum GameRosterStatusEnum: string
{
    case SELECTED = 'selected';
    case PLAYED = 'played';
    case DID_NOT_PLAY = 'did_not_play';
    case EXCLUDED = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::SELECTED => 'В составе',
            self::PLAYED => 'Играл',
            self::DID_NOT_PLAY => 'Не вышел',
            self::EXCLUDED => 'Исключён',
        };
    }
}
