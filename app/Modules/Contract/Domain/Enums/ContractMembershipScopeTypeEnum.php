<?php

namespace App\Modules\Contract\Domain\Enums;

use App\Modules\Venue\Domain\Models\Venue;

enum ContractMembershipScopeTypeEnum: string
{
    case VENUE = 'venue';
    case EVENT = 'event';
    case TEAM = 'team';
    case COMPANY = 'company';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадка',
            self::EVENT => 'Событие',
            self::TEAM => 'Команда',
            self::COMPANY => 'Компания',
        };
    }

    public function modelClass(): ?string
    {
        return match ($this) {
            self::VENUE => Venue::class,

            self::EVENT,
            self::TEAM,
            self::COMPANY => null,
        };
    }

    public function showRouteName(): ?string
    {
        return match ($this) {
            self::VENUE => 'venues.show',

            self::EVENT,
            self::TEAM,
            self::COMPANY => null,
        };
    }

    public function titleAttribute(): string
    {
        return match ($this) {
            self::VENUE => 'name',

            self::EVENT,
            self::TEAM,
            self::COMPANY => 'id',
        };
    }

    public function accessLevelEnumClass(): ?string
    {
        return match ($this) {
            self::VENUE => VenueMembershipAccessLevelEnum::class,

            self::EVENT,
            self::TEAM,
            self::COMPANY => null,
        };
    }
}
