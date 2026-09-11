<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SportsSectionStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик', self::ACTIVE => 'Активна', self::PAUSED => 'Приостановлена', self::ARCHIVED => 'В архиве',
        };
    }
}
