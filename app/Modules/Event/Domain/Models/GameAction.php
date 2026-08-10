<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GameActionTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id',
    'game_period_id',
    'sequence',
    'game_side_id',
    'user_id',
    'created_by_actor_id',
    'type',
    'points',
    'payload',
    'occurred_at',
])]
class GameAction extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function gamePeriod(): BelongsTo
    {
        return $this->belongsTo(GamePeriod::class);
    }

    public function gameSide(): BelongsTo
    {
        return $this->belongsTo(GameSide::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'type' => GameActionTypeEnum::class,
            'points' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
