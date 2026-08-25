<?php

namespace App\Support\Features;

enum VenueRentalFeature: string
{
    case RENTAL_FLOW = 'rental_flow';
    case COORDINATION = 'coordination';
    case EXTERNAL_PAYMENT = 'external_payment';
    case ATTENDANCE_V2 = 'attendance_v2';

    public function label(): string
    {
        return match ($this) {
            self::RENTAL_FLOW => 'Новый flow аренды',
            self::COORDINATION => 'Согласование аренды',
            self::EXTERNAL_PAYMENT => 'Внешняя оплата аренды',
            self::ATTENDANCE_V2 => 'Подтверждение участников V2',
        };
    }
}
