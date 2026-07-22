<?php

namespace App\Modules\Event\Domain\Enums;

enum EventStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PUBLISHED => 'Опубликовано',
            self::COMPLETED => 'Состоялось',
            self::CANCELLED => 'Отменено',
        };
    }
}
