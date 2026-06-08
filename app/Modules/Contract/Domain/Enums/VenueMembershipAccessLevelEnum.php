<?php

namespace App\Modules\Contract\Domain\Enums;

use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;

enum VenueMembershipAccessLevelEnum: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MANAGER = 'manager';
    case STAFF = 'staff';
    case AGENT = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Владелец',
            self::ADMIN => 'Администратор',
            self::MANAGER => 'Менеджер',
            self::STAFF => 'Сотрудник',
            self::AGENT => 'Агент',
        };
    }

    /**
     * @return array<VenuePermissionEnum>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::OWNER => VenuePermissionEnum::cases(),
            self::ADMIN => [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::EDIT,
                VenuePermissionEnum::EDIT_SCHEDULE,
            ],
            self::MANAGER => [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::EDIT_SCHEDULE,
            ],
            self::STAFF,
            self::AGENT => [
                VenuePermissionEnum::VIEW,
            ],
        };
    }
}
