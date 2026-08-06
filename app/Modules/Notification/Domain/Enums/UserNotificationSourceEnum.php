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
        };
    }
}
