<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'session_id',
    'decision_id',
    'event_id',
    'created_by_actor_id',
    'transitioned_at',
])]
class CoordinationEventTransition extends Model
{
    public function session(): BelongsTo
    {
        return $this->belongsTo(CoordinationSession::class, 'session_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(CoordinationDecision::class, 'decision_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    protected function casts(): array
    {
        return ['transitioned_at' => 'immutable_datetime'];
    }
}
