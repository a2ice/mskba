<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'poll_id', 'option_id', 'decided_by_actor_id', 'decided_at'])]
class CoordinationDecision extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(CoordinationSession::class, 'session_id');
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }

    public function decidedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'decided_by_actor_id');
    }

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }
}
