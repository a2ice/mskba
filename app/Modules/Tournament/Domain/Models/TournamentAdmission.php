<?php

namespace App\Modules\Tournament\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionCandidateTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tournament_id', 'candidate_type', 'team_id', 'user_id', 'direction', 'status', 'requested_by_actor_id', 'responded_by_actor_id', 'responded_at', 'comment'])]
class TournamentAdmission extends Model
{
    use Auditable;

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
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

    public function entry(): HasOne
    {
        return $this->hasOne(TournamentEntry::class, 'admission_id');
    }

    protected function casts(): array
    {
        return ['candidate_type' => TournamentAdmissionCandidateTypeEnum::class, 'direction' => TournamentAdmissionDirectionEnum::class, 'status' => TournamentAdmissionStatusEnum::class, 'responded_at' => 'immutable_datetime'];
    }
}
