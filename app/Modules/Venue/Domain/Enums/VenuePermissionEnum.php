<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenuePermissionEnum: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
    case EDIT_SCHEDULE = 'edit.schedule';

    case REMOVE = 'remove';
    case MANAGE_MEMBERSHIPS = 'rental.memberships.manage';
    case MANAGE_BOOKING_POLICY = 'rental.policy.manage';
    case VIEW_BOOKING_REQUESTS = 'rental.bookings.view';
    case DECIDE_BOOKING_REQUESTS = 'rental.bookings.decide';
    case VIEW_PAYMENTS = 'rental.payments.view';
    case CONFIRM_PAYMENTS = 'rental.payments.confirm';

    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Просмотр',
            self::EDIT => 'Редактирование',
            self::EDIT_SCHEDULE => 'Редактирование расписания',

            self::REMOVE => 'Удаление',
            self::MANAGE_MEMBERSHIPS => 'Управление коммерческими ролями',
            self::MANAGE_BOOKING_POLICY => 'Управление условиями аренды',
            self::VIEW_BOOKING_REQUESTS => 'Просмотр заявок на аренду',
            self::DECIDE_BOOKING_REQUESTS => 'Решения по заявкам на аренду',
            self::VIEW_PAYMENTS => 'Просмотр оплат',
            self::CONFIRM_PAYMENTS => 'Подтверждение оплат',
        };
    }
}
