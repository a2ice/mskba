<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SportsSectionPermissionEnum: string
{
    case MANAGE = 'section.manage';
    case MANAGE_COACHES = 'section.coaches.manage';
    case MANAGE_TRAINEES = 'section.trainees.manage';
    case MANAGE_SESSIONS = 'section.sessions.manage';
    case MANAGE_PRICING = 'section.pricing.manage';
    case MANAGE_CONTACTS = 'section.contacts.manage';

    public function label(): string
    {
        return match ($this) {
            self::MANAGE => 'Управлять секцией', self::MANAGE_COACHES => 'Управлять тренерами', self::MANAGE_TRAINEES => 'Управлять тренируемыми', self::MANAGE_SESSIONS => 'Управлять занятиями', self::MANAGE_PRICING => 'Управлять ценами', self::MANAGE_CONTACTS => 'Управлять контактами',
        };
    }
}
