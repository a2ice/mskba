<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenuePermissionEnum: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
    case EDIT_SCHEDULE = 'edit.schedule';

    case REMOVE = 'remove';
    case MANAGE_MEMBERSHIPS = 'rental.memberships.manage';
    case MANAGE_CONTRACTS = 'venue.contracts.manage';
    case MANAGE_BOOKING_POLICY = 'rental.policy.manage';
    case MANAGE_GROUP_OFFERS = 'venue.group-offers.manage';
    case VIEW_EVENT_INTERESTS = 'rental.interests.view';
    case PROPOSE_EVENT_BOOKING = 'rental.interests.propose-booking';
    case VIEW_BOOKING_REQUESTS = 'rental.bookings.view';
    // Three independent capabilities: receive/read + converse, approve, reject.
    // The former combined 'rental.bookings.decide' is migrated to both actions.
    case CONFIRM_BOOKING_REQUESTS = 'rental.bookings.confirm';
    case REJECT_BOOKING_REQUESTS = 'rental.bookings.reject';
    case VIEW_PAYMENTS = 'rental.payments.view';
    case CONFIRM_PAYMENTS = 'rental.payments.confirm';
    case MANAGE_FUNDS = 'venue.wallet.manage';

    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Просмотр',
            self::EDIT => 'Редактирование',
            self::EDIT_SCHEDULE => 'Редактирование расписания',

            self::REMOVE => 'Удаление',
            self::MANAGE_MEMBERSHIPS => 'Управление коммерческими ролями',
            self::MANAGE_CONTRACTS => 'Назначение контрактов площадки',
            self::MANAGE_BOOKING_POLICY => 'Управление условиями аренды',
            self::MANAGE_GROUP_OFFERS => 'Управление промокодами площадки',
            self::VIEW_EVENT_INTERESTS => 'Просмотр предварительного интереса к аренде',
            self::PROPOSE_EVENT_BOOKING => 'Предложить бронирование по предзаявке',
            self::VIEW_BOOKING_REQUESTS => 'Получение заявок, просмотр и переписка',
            self::CONFIRM_BOOKING_REQUESTS => 'Принятие и подтверждение заявок',
            self::REJECT_BOOKING_REQUESTS => 'Отклонение и отмена заявок',
            self::VIEW_PAYMENTS => 'Просмотр оплат',
            self::CONFIRM_PAYMENTS => 'Подтверждение оплат',
            self::MANAGE_FUNDS => 'Распоряжаться средствами площадки',
        };
    }
}
