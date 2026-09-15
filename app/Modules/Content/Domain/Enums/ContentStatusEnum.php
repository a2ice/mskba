<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PUBLISHED => 'Опубликован',
            self::ARCHIVED => 'Архив',
        };
    }
}
