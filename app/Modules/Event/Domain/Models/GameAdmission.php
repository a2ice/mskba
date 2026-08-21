<?php

namespace App\Modules\Event\Domain\Models;

use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id',
    'candidate_type',
    'team_id',
    'user_id',
    'direction',
    'status',
    'requested_by_actor_id',
    'responded_by_actor_id',
    'responded_at',
    'response_comment',
])]
class GameAdmission extends Model
{
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'requested_by_actor_id');
    }

    public function respondedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'responded_by_actor_id');
    }

    protected function casts(): array
    {
        return [
            'candidate_type' => GameAdmissionCandidateTypeEnum::class,
            'direction' => GameAdmissionDirectionEnum::class,
            'status' => GameAdmissionStatusEnum::class,
            'responded_at' => 'immutable_datetime',
        ];
    }
}
