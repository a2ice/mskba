<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'game_id',
    'number',
    'status',
    'actual_started_at',
    'started_by_actor_id',
    'actual_ended_at',
    'ended_by_actor_id',
    'side_a_score',
    'side_b_score',
])]
class GamePeriod extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }

    public function startedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'started_by_actor_id');
    }

    public function endedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'ended_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => GamePeriodStatusEnum::class,
            'actual_started_at' => 'immutable_datetime',
            'actual_ended_at' => 'immutable_datetime',
            'side_a_score' => 'integer',
            'side_b_score' => 'integer',
        ];
    }
}
