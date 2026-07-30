<?php

namespace App\Modules\Contract\Domain\Enums;

use App\Modules\Team\Domain\Models\Team;
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
            self::TEAM => Team::class,

            self::EVENT,
            self::COMPANY => null,
        };
    }

    public function showRouteName(): ?string
    {
        return match ($this) {
            self::VENUE => 'venues.show',
            self::TEAM => 'teams.show',

            self::EVENT,
            self::COMPANY => null,
        };
    }

    public function titleAttribute(): string
    {
        return match ($this) {
            self::VENUE,
            self::TEAM => 'name',

            self::EVENT,
            self::COMPANY => 'id',
        };
    }

    public function accessLevelEnumClass(): ?string
    {
        return match ($this) {
            self::VENUE => VenueMembershipAccessLevelEnum::class,
            self::TEAM => TeamMembershipAccessLevelEnum::class,

            self::EVENT,
            self::COMPANY => null,
        };
    }
}
