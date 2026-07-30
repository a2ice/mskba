<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GameStatisticsModeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'side_a_size',
    'side_b_size',
    'is_time_scheduled',
    'statistics_mode',
    'statistics_status',
    'statistics_version',
    'statistics_confirmed_at',
    'statistics_confirmed_by_actor_id',
])]
class GameDetail extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function statisticsConfirmedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'statistics_confirmed_by_actor_id');
    }

    public function formatLabel(): string
    {
        return $this->side_a_size.'×'.$this->side_b_size;
    }

    protected function casts(): array
    {
        return [
            'is_time_scheduled' => 'boolean',
            'statistics_mode' => GameStatisticsModeEnum::class,
            'statistics_status' => GameStatisticsStatusEnum::class,
            'statistics_confirmed_at' => 'immutable_datetime',
        ];
    }
}
