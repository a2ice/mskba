<?php

namespace App\Modules\SportsSection\Domain\Enums;

enum SportsSectionAccessLevelEnum: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Владелец', self::MANAGER => 'Менеджер'
        };
    }

    /** @return list<SportsSectionPermissionEnum> */
    public function defaultPermissions(): array
    {
        return $this === self::OWNER ? SportsSectionPermissionEnum::cases() : [
            SportsSectionPermissionEnum::MANAGE,
            SportsSectionPermissionEnum::MANAGE_TRAINEES,
            SportsSectionPermissionEnum::MANAGE_SESSIONS,
            SportsSectionPermissionEnum::MANAGE_CONTACTS,
        ];
    }
}
