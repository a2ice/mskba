<?php

namespace App\Modules\Event\Domain\Models;

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

    public function points(): int
    {
        return ($this->close_made * 2)
            + ($this->mid_made * 2)
            + ($this->three_made * 3)
            + $this->free_throw_made;
    }

    protected function casts(): array
    {
        return array_fill_keys(self::COUNTING_FIELDS, 'integer');
    }
}
