<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SectionPricingTypeEnum: string
{
    case FREE = 'free';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Бесплатно', self::PAID => 'Платно'
        };
    }
}
