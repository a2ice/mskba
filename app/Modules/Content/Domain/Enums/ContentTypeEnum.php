<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentTypeEnum: string
{
    case MATERIAL = 'material';
    case EVENT = 'event';
    case VENUE = 'venue';
    case USER = 'user';

    public function label(): string
    {
        return match ($this) {
            self::MATERIAL => 'Материал',
            self::EVENT => 'О мероприятии',
            self::VENUE => 'О площадке',
            self::USER => 'Для пользователей',
        };
    }

    public function supportsRelatedEntity(): bool
    {
        return $this !== self::MATERIAL;
    }

    public function requiresRelatedEntity(): bool
    {
        return match ($this) {
            self::EVENT, self::VENUE => true,
            self::MATERIAL, self::USER => false,
        };
    }
}
