<?php

namespace App\Modules\Moderation\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'subject_id',
    'submitted_by_actor_id',
    'reviewed_by_user_id',
    'status',
    'submitted_at',
    'reviewed_at',
])]
class ModerationRequest extends Model
{
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
        return $this->hasMany(ModerationMessage::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ModerationTypeEnum::class,
            'subject_id' => 'integer',
            'submitted_by_actor_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'status' => ModerationRequestStatusEnum::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
