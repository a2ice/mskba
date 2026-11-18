<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentTypeEnum: string
{
    case MATERIAL = 'material';
    case EVENT = 'event';
    case VENUE = 'venue';
    case USER = 'user';
    case FAQ = 'faq';
    case SYSTEM_UPDATE = 'system_update';

    public function label(): string
    {
        return match ($this) {
            self::MATERIAL => 'Материал',
            self::EVENT => 'О мероприятии',
            self::VENUE => 'О площадке',
            self::USER => 'Для пользователей',
            self::FAQ => 'FAQ',
            self::SYSTEM_UPDATE => 'Обновления платформы',
        };
    }

    public function supportsRelatedEntity(): bool
    {
        return match ($this) {
            self::EVENT, self::VENUE, self::USER => true,
            self::MATERIAL, self::FAQ, self::SYSTEM_UPDATE => false,
        };
    }

    public function requiresRelatedEntity(): bool
    {
        return match ($this) {
            self::EVENT, self::VENUE => true,
            self::MATERIAL, self::USER, self::FAQ, self::SYSTEM_UPDATE => false,
        };
    }
}
