<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueTypeEnum: string
{
    case SPORTS_HALL = 'sports_hall';
    case SCHOOL = 'school';
    case UNIVERSITY = 'university';
    case SPORTS_COMPLEX = 'sports_complex';
    case ARENA = 'arena';
    case STREET_COURT = 'street_court';

    public function label(): string
    {
        return match ($this) {
            self::SPORTS_HALL => 'Спортзал',
            self::SCHOOL => 'Школа',
            self::UNIVERSITY => 'Университет',
            self::SPORTS_COMPLEX => 'Спорткомплекс',
            self::ARENA => 'Арена',
            self::STREET_COURT => 'Уличная площадка',
        };
    }

    public function publicSlug(): string
    {
        return match ($this) {
            self::SPORTS_HALL => 'halls',
            self::SCHOOL => 'schools',
            self::UNIVERSITY => 'universities',
            self::SPORTS_COMPLEX => 'sports-complexes',
            self::ARENA => 'arenas',
            self::STREET_COURT => 'street-courts',
        };
    }
}
