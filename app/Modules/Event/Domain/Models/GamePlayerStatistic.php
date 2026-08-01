<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'game_side_id',
    'user_id',
    'minutes',
    'close_made',
    'close_attempted',
    'mid_made',
    'mid_attempted',
    'three_made',
    'three_attempted',
    'free_throw_made',
    'free_throw_attempted',
    'offensive_rebounds',
    'defensive_rebounds',
    'assists',
    'steals',
    'blocks',
    'turnovers',
    'fouls',
])]
class GamePlayerStatistic extends Model
{
    public const COUNTING_FIELDS = [
        'minutes',
        'close_made',
        'close_attempted',
        'mid_made',
        'mid_attempted',
        'three_made',
        'three_attempted',
        'free_throw_made',
        'free_throw_attempted',
        'offensive_rebounds',
        'defensive_rebounds',
        'assists',
        'steals',
        'blocks',
        'turnovers',
        'fouls',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function gameSide(): BelongsTo
    {
        return $this->belongsTo(GameSide::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function points(GameScoringTypeEnum $scoringType = GameScoringTypeEnum::STREETBALL): int
    {
        $regularShotPoints = $scoringType === GameScoringTypeEnum::STREETBALL ? 1 : 2;
        $longShotPoints = $scoringType === GameScoringTypeEnum::STREETBALL ? 2 : 3;

        return ($this->close_made * $regularShotPoints)
            + ($this->mid_made * $regularShotPoints)
            + ($this->three_made * $longShotPoints)
            + $this->free_throw_made;
    }

    protected function casts(): array
    {
        return array_fill_keys(self::COUNTING_FIELDS, 'integer');
    }
}
