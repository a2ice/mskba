<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsModeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'event_id',
    'created_by_actor_id',
    'title',
    'description',
    'status',
    'recruitment_mode',
    'sides_confirmed_at',
    'sides_confirmed_by_actor_id',
    'format',
    'timing_mode',
    'side_a_size',
    'side_b_size',
    'scoring_type',
    'periods_count',
    'statistics_mode',
    'statistics_status',
    'statistics_version',
    'statistics_confirmed_at',
    'statistics_confirmed_by_actor_id',
    'scheduled_starts_at',
    'scheduled_ends_at',
    'actual_started_at',
    'actual_started_by_actor_id',
    'actual_ended_at',
    'actual_ended_by_actor_id',
    'ended_early',
    'status_comment',
    'completed_at',
    'completed_by_actor_id',
    'cancelled_at',
    'cancelled_by_actor_id',
    'cancellation_reason',
    'winner_game_side_id',
])]
class Game extends Model
{
    use SoftDeletes;

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function sidesConfirmedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'sides_confirmed_by_actor_id');
    }

    public function sides(): HasMany
    {
        return $this->hasMany(GameSide::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(GameAdmission::class);
    }

    public function rosterEntries(): HasMany
    {
        return $this->hasMany(GameRosterEntry::class);
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(GamePlayerStatistic::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(GamePeriod::class)->orderBy('number');
    }

    public function activePeriod(): HasOne
    {
        return $this->hasOne(GamePeriod::class)->where('status', 'in_progress');
    }

    public function tournamentMatch(): HasOne
    {
        return $this->hasOne(TournamentMatch::class);
    }

    public function latestAction(): HasOne
    {
        return $this->hasOne(GameAction::class)->ofMany('sequence', 'max');
    }

    public function latestTeamAction(): HasOne
    {
        return $this->hasOne(GameAction::class)->ofMany(
            ['sequence' => 'max'],
            fn ($query) => $query->whereNotNull('game_side_id'),
        );
    }

    public function winnerSide(): BelongsTo
    {
        return $this->belongsTo(GameSide::class, 'winner_game_side_id');
    }

    public function statisticsConfirmedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'statistics_confirmed_by_actor_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function formatLabel(): string
    {
        return $this->side_a_size.'×'.$this->side_b_size;
    }

    public function sidesAreConfirmed(): bool
    {
        return $this->sides_confirmed_at !== null;
    }

    protected function casts(): array
    {
        return [
            'status' => GameStatusEnum::class,
            'recruitment_mode' => GameRecruitmentModeEnum::class,
            'sides_confirmed_at' => 'immutable_datetime',
            'format' => GameFormatEnum::class,
            'timing_mode' => GameTimingModeEnum::class,
            'scoring_type' => GameScoringTypeEnum::class,
            'periods_count' => 'integer',
            'statistics_mode' => GameStatisticsModeEnum::class,
            'statistics_status' => GameStatisticsStatusEnum::class,
            'statistics_confirmed_at' => 'immutable_datetime',
            'scheduled_starts_at' => 'immutable_datetime',
            'scheduled_ends_at' => 'immutable_datetime',
            'actual_started_at' => 'immutable_datetime',
            'actual_ended_at' => 'immutable_datetime',
            'ended_early' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
