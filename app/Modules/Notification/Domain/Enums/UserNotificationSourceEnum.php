<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationSourceEnum: string
{
    case IDENTITY_REGISTRATION = 'identity.registration';
    case CONTACT_CONFIRMATION = 'contact.confirmation';
    case TEAM_INVITATION_CREATED = 'team.invitation.created';
    case TEAM_INVITATION_ACCEPTED = 'team.invitation.accepted';
    case TEAM_INVITATION_DECLINED = 'team.invitation.declined';
    case TEAM_JOIN_REQUEST_SUBMITTED = 'team.join_request.submitted';
    case TEAM_JOIN_REQUEST_ACCEPTED = 'team.join_request.accepted';
    case TEAM_JOIN_REQUEST_REJECTED = 'team.join_request.rejected';
    case TEAM_JOIN_REQUEST_BLOCKED = 'team.join_request.blocked';
    case TEAM_JOIN_REQUEST_UNBLOCKED = 'team.join_request.unblocked';
    case VENUE_OWNERSHIP_CLAIM_SUBMITTED = 'venue.ownership_claim.submitted';
    case VENUE_OWNERSHIP_CLAIM_APPROVED = 'venue.ownership_claim.approved';
    case VENUE_OWNERSHIP_CLAIM_REJECTED = 'venue.ownership_claim.rejected';
    case VENUE_OWNERSHIP_CLAIM_MESSAGE = 'venue.ownership_claim.message';
    case VENUE_OWNERSHIP_STATUS_CHANGED = 'venue.ownership.status_changed';
    case VENUE_USER_RESTRICTION_IMPOSED = 'venue.user_restriction.imposed';
    case VENUE_USER_RESTRICTION_REVOKED = 'venue.user_restriction.revoked';
    case VENUE_MEMBERSHIP_GRANTED = 'venue.membership.granted';
    case VENUE_MEMBERSHIP_REVOKED = 'venue.membership.revoked';
    case VENUE_RENTAL_COORDINATION_JOINED = 'venue_rental.coordination.joined';
    case VENUE_BOOKING_REQUESTED = 'venue_booking.requested';
    case VENUE_BOOKING_HELD = 'venue_booking.held';
    case VENUE_BOOKING_CONFIRMED = 'venue_booking.confirmed';
    case VENUE_BOOKING_REJECTED = 'venue_booking.rejected';
    case VENUE_BOOKING_CANCELLED = 'venue_booking.cancelled';
    case VENUE_BOOKING_EXPIRED = 'venue_booking.expired';
    case VENUE_BOOKING_MESSAGE = 'venue_booking.message';
    case VENUE_BOOKING_ATTENDANCE_OPENED = 'venue_booking.attendance.opened';

    public function label(): string
    {
        return match ($this) {
            self::IDENTITY_REGISTRATION => 'Регистрация пользователя',
            self::CONTACT_CONFIRMATION => 'Подтверждение контакта',
            self::TEAM_INVITATION_CREATED => 'Приглашение в команду',
            self::TEAM_INVITATION_ACCEPTED => 'Принятие приглашения в команду',
            self::TEAM_INVITATION_DECLINED => 'Отклонение приглашения в команду',
            self::TEAM_JOIN_REQUEST_SUBMITTED => 'Заявка на вступление в команду',
            self::TEAM_JOIN_REQUEST_ACCEPTED => 'Принятие заявки в команду',
            self::TEAM_JOIN_REQUEST_REJECTED => 'Отклонение заявки в команду',
            self::TEAM_JOIN_REQUEST_BLOCKED => 'Блокировка заявок в команду',
            self::TEAM_JOIN_REQUEST_UNBLOCKED => 'Разблокировка заявок в команду',
            self::VENUE_OWNERSHIP_CLAIM_SUBMITTED => 'Отправка заявки на управление площадкой',
            self::VENUE_OWNERSHIP_CLAIM_APPROVED => 'Одобрение заявки на управление площадкой',
            self::VENUE_OWNERSHIP_CLAIM_REJECTED => 'Отклонение заявки на управление площадкой',
            self::VENUE_OWNERSHIP_CLAIM_MESSAGE => 'Сообщение по заявке на управление площадкой',
            self::VENUE_OWNERSHIP_STATUS_CHANGED => 'Изменение статуса владения площадкой',
            self::VENUE_USER_RESTRICTION_IMPOSED => 'Ограничение заявок пользователя по площадке',
            self::VENUE_USER_RESTRICTION_REVOKED => 'Снятие ограничения заявок пользователя по площадке',
            self::VENUE_MEMBERSHIP_GRANTED => 'Выдача коммерческой роли площадки',
            self::VENUE_MEMBERSHIP_REVOKED => 'Отзыв коммерческой роли площадки',
            self::VENUE_RENTAL_COORDINATION_JOINED => 'Вступление в сбор по аренде',
            self::VENUE_BOOKING_REQUESTED => 'Новая заявка на аренду площадки',
            self::VENUE_BOOKING_HELD => 'Заявка на аренду принята в работу',
            self::VENUE_BOOKING_CONFIRMED => 'Подтверждение аренды площадки',
            self::VENUE_BOOKING_REJECTED => 'Отклонение аренды площадки',
            self::VENUE_BOOKING_CANCELLED => 'Отмена аренды площадки',
            self::VENUE_BOOKING_EXPIRED => 'Истечение заявки на аренду',
            self::VENUE_BOOKING_MESSAGE => 'Сообщение по аренде площадки',
            self::VENUE_BOOKING_ATTENDANCE_OPENED => 'Открытие подтверждения явки',
        };
    }
}
