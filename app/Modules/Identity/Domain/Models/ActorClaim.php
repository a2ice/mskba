<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'claimed_actor_id',
    'claimed_by_user_id',
    'claimed_by_actor_id',
    'claimed_at',
])]
class ActorClaim extends Model
{
    protected function casts(): array
    {
        return [
            'claimed_actor_id' => 'integer',
            'claimed_by_user_id' => 'integer',
            'claimed_by_actor_id' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    public function claimedActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'claimed_actor_id');
    }

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function claimedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'claimed_by_actor_id');
    }
}
