<?php

namespace App\Modules\Contract\Domain\Enums;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\League\Domain\Models\League;
use App\Modules\League\Domain\Models\LeagueSeason;
use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;

enum ContractMembershipScopeTypeEnum: string
{
    case VENUE = 'venue';
    case VENUE_COURT = 'venue_court';
    case EVENT = 'event';
    case TEAM = 'team';
    case COMPANY = 'company';
    case TOURNAMENT = 'tournament';
    case LEAGUE = 'league';
    case SEASON = 'season';
    case SPORTS_SECTION = 'sports_section';

    public function label(): string
    {
        return match ($this) {
            self::VENUE => 'Площадка',
            self::VENUE_COURT => 'Зал / зона',
            self::EVENT => 'Событие',
            self::TEAM => 'Команда',
            self::COMPANY => 'Компания',
            self::TOURNAMENT => 'Турнир',
            self::LEAGUE => 'Лига',
            self::SEASON => 'Сезон',
            self::SPORTS_SECTION => 'Секция',
        };
    }

    public function modelClass(): ?string
    {
        return match ($this) {
            self::VENUE => Venue::class,
            self::VENUE_COURT => VenueCourt::class,
            self::TEAM => Team::class,
            self::EVENT => Event::class,
            self::TOURNAMENT => Tournament::class,
            self::LEAGUE => League::class,
            self::SEASON => LeagueSeason::class,
            self::SPORTS_SECTION => SportsSection::class,

            self::COMPANY => null,
        };
    }

    public function showRouteName(): ?string
    {
        return match ($this) {
            self::VENUE => 'venues.show',
            self::VENUE_COURT => null,
            self::TEAM => 'teams.show',
            self::EVENT => 'events.show',
            self::TOURNAMENT => 'tournaments.show',
            self::LEAGUE => 'leagues.show',
            self::SEASON => null,
            self::SPORTS_SECTION => 'sports-sections.show',

            self::COMPANY => null,
        };
    }

    public function titleAttribute(): string
    {
        return match ($this) {
            self::VENUE,
            self::VENUE_COURT,
            self::TEAM,
            self::LEAGUE,
            self::SEASON => 'name',
            self::TOURNAMENT,
            self::EVENT => 'title',
            self::SPORTS_SECTION => 'name',

            self::COMPANY => 'id',
        };
    }

    public function accessLevelEnumClass(): ?string
    {
        return match ($this) {
            self::VENUE,
            self::VENUE_COURT => VenueMembershipAccessLevelEnum::class,
            self::TEAM => TeamMembershipAccessLevelEnum::class,
            self::SPORTS_SECTION => SportsSectionAccessLevelEnum::class,

            self::EVENT,
            self::COMPANY,
            self::TOURNAMENT,
            self::LEAGUE,
            self::SEASON => null,
        };
    }
}
