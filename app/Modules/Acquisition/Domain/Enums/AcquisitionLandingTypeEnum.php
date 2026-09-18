<?php

namespace App\Modules\Acquisition\Domain\Enums;

enum AcquisitionLandingTypeEnum: string
{
    case ONBOARDING = 'onboarding';
    case HOME = 'home';
    case VENUE = 'venue';
    case EVENT = 'event';
    case SPORTS_SECTION = 'sports_section';

    public function label(): string
    {
        return match ($this) {
            self::ONBOARDING => 'Присоединение / onboarding',
            self::HOME => 'Главная MSKBA',
            self::VENUE => 'Страница площадки',
            self::EVENT => 'Страница мероприятия',
            self::SPORTS_SECTION => 'Страница секции',
        };
    }

    public function needsTarget(): bool
    {
        return in_array($this, [self::VENUE, self::EVENT, self::SPORTS_SECTION], true);
    }

    public function targetLabel(): ?string
    {
        return match ($this) {
            self::VENUE => 'Площадка для посадочной',
            self::EVENT => 'Мероприятие для посадочной',
            self::SPORTS_SECTION => 'Секция для посадочной',
            default => null,
        };
    }
}
