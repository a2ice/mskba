<?php

namespace App\Modules\Coordination\Domain\Models;

use App\Modules\Coordination\Domain\Enums\CoordinationContextTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organizer_actor_id',
    'title',
    'description',
    'status',
    'context_type',
    'context_id',
    'closed_at',
    'cancelled_at',
    'cancelled_by_actor_id',
])]
class CoordinationSession extends Model
{
    public function organizerActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'organizer_actor_id');
    }

    public function cancelledByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'cancelled_by_actor_id');
    }

    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class, 'session_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(CoordinationDecision::class, 'session_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CoordinationSessionStatusEnum::class,
            'context_type' => CoordinationContextTypeEnum::class,
            'context_id' => 'integer',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
