<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueCreationRoleEnum: string
{
    case REPRESENTATIVE = 'representative';
    case CONTRIBUTOR = 'contributor';

    public function label(): string
    {
        return match ($this) {
            self::REPRESENTATIVE => 'Как представитель площадки',
            self::CONTRIBUTOR => 'Просто хочу добавить площадку',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::REPRESENTATIVE => 'Смогу предоставить документы для подтверждения права управления площадкой (скан или оригинал).',
            self::CONTRIBUTOR => 'Знаю место для баскетбола и хочу поделиться им. Представлять площадку и подтверждать права документами не нужно.',
        };
    }
}
