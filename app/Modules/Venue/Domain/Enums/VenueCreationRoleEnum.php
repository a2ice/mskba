<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueCreationRoleEnum: string
{
    case REPRESENTATIVE = 'representative';
    case CONTRIBUTOR = 'contributor';

    public function label(): string
    {
        return match ($this) {
            self::REPRESENTATIVE => 'Я представитель площадки',
            self::CONTRIBUTOR => 'Хочу добавить площадку в каталог',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::REPRESENTATIVE => 'Я владелец или уполномоченный представитель. Для подтверждения управления понадобится скан документа о полномочиях. Его можно прикрепить после добавления площадки.',
            self::CONTRIBUTOR => 'Знаю место для баскетбола и хочу поделиться им. Представлять площадку и подтверждать права документами не нужно.',
        };
    }
}
