<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'venue_id',
    'submitted_by_actor_id',
    'reviewed_by_user_id',
    'status',
    'submitted_at',
    'reviewed_at',
])]
class VenueModerationRequest extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function submittedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'submitted_by_actor_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VenueModerationMessage::class);
    }

    protected function casts(): array
    {
        return [
            'venue_id' => 'integer',
            'submitted_by_actor_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'status' => VenueModerationRequestStatusEnum::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
