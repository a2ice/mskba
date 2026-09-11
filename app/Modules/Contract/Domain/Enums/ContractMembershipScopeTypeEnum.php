<?php

namespace App\Modules\Contract\Domain\Enums;

use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;

enum ContractMembershipScopeTypeEnum: string
{
    case VENUE = 'venue';
    case EVENT = 'event';
    case TEAM = 'team';
    case COMPANY = 'company';
    case TOURNAMENT = 'tournament';
    case SPORTS_SECTION = 'sports_section';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадка',
            self::EVENT => 'Событие',
            self::TEAM => 'Команда',
            self::COMPANY => 'Компания',
            self::TOURNAMENT => 'Турнир',
            self::SPORTS_SECTION => 'Секция',
        };
    }

    public function modelClass(): ?string
    {
        return match ($this) {
            self::VENUE => Venue::class,
            self::TEAM => Team::class,
            self::TOURNAMENT => Tournament::class,
            self::SPORTS_SECTION => SportsSection::class,

            self::EVENT,
            self::COMPANY => null,
        };
    }

    public function showRouteName(): ?string
    {
        return match ($this) {
            self::VENUE => 'venues.show',
            self::TEAM => 'teams.show',
            self::TOURNAMENT => 'tournaments.show',
            self::SPORTS_SECTION => 'sports-sections.show',

            self::EVENT,
            self::COMPANY => null,
        };
    }

    public function titleAttribute(): string
    {
        return match ($this) {
            self::VENUE,
            self::TEAM => 'name',
            self::TOURNAMENT => 'title',
            self::SPORTS_SECTION => 'name',

            self::EVENT,
            self::COMPANY => 'id',
        };
    }

    public function accessLevelEnumClass(): ?string
    {
        return match ($this) {
            self::VENUE => VenueMembershipAccessLevelEnum::class,
            self::TEAM => TeamMembershipAccessLevelEnum::class,
            self::SPORTS_SECTION => SportsSectionAccessLevelEnum::class,

            self::EVENT,
            self::COMPANY,
            self::TOURNAMENT => null,
        };
    }
}
