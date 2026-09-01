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
    case BOOKING_OPERATOR = 'booking_operator';
    case FINANCE_VIEWER = 'finance_viewer';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Владелец',
            self::ADMIN => 'Администратор',
            self::MANAGER => 'Менеджер',
            self::STAFF => 'Сотрудник',
            self::AGENT => 'Агент',
            self::BOOKING_OPERATOR => 'Оператор бронирований',
            self::FINANCE_VIEWER => 'Финансовый наблюдатель',
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
                VenuePermissionEnum::MANAGE_BOOKING_POLICY,
                VenuePermissionEnum::VIEW_BOOKING_REQUESTS,
                VenuePermissionEnum::DECIDE_BOOKING_REQUESTS,
            ],
            self::STAFF,
            self::AGENT => [
                VenuePermissionEnum::VIEW,
            ],
            self::BOOKING_OPERATOR => [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::VIEW_BOOKING_REQUESTS,
                VenuePermissionEnum::DECIDE_BOOKING_REQUESTS,
            ],
            self::FINANCE_VIEWER => [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::VIEW_PAYMENTS,
            ],
        };
    }

    /** @return array<VenuePermissionEnum> */
    public function allowedPermissions(): array
    {
        return match ($this) {
            self::OWNER => VenuePermissionEnum::cases(),
            self::MANAGER => [
                ...$this->defaultPermissions(),
                VenuePermissionEnum::MANAGE_MEMBERSHIPS,
                VenuePermissionEnum::VIEW_PAYMENTS,
                VenuePermissionEnum::CONFIRM_PAYMENTS,
            ],
            self::BOOKING_OPERATOR => $this->defaultPermissions(),
            self::FINANCE_VIEWER => [
                ...$this->defaultPermissions(),
                VenuePermissionEnum::CONFIRM_PAYMENTS,
            ],
            self::ADMIN, self::STAFF, self::AGENT => $this->defaultPermissions(),
        };
    }

    public function isCommercialRole(): bool
    {
        return in_array($this, [
            self::OWNER,
            self::MANAGER,
            self::BOOKING_OPERATOR,
            self::FINANCE_VIEWER,
        ], true);
    }
}
