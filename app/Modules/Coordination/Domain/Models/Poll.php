<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'session_id',
    'question',
    'subject_type',
    'selection_mode',
    'results_visibility',
    'status',
    'allows_suggestions',
    'allows_vote_changes',
    'is_anonymous',
    'closes_at',
    'closed_at',
    'closed_by_actor_id',
])]
class Poll extends Model
{
    protected $table = 'coordination_polls';

    public function session(): BelongsTo
    {
        return $this->belongsTo(CoordinationSession::class, 'session_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id')->orderBy('sort_order')->orderBy('id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(PollBallot::class, 'poll_id');
    }

    public function closedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'closed_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'subject_type' => PollSubjectTypeEnum::class,
            'selection_mode' => PollSelectionModeEnum::class,
            'results_visibility' => PollResultsVisibilityEnum::class,
            'status' => PollStatusEnum::class,
            'allows_suggestions' => 'boolean',
            'allows_vote_changes' => 'boolean',
            'is_anonymous' => 'boolean',
            'closes_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
