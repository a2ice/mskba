<?php

namespace App\Modules\Coordination\Domain\Enums;

enum PollSelectionModeEnum: string
{
    case SINGLE = 'single';
    case MULTIPLE = 'multiple';

    public function label(): string
    {
        return match ($this) {
            self::SINGLE => 'Один вариант',
            self::MULTIPLE => 'Несколько вариантов',
        };
    }
}
