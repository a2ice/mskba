<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueModerationMessageDirectionEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'venue_moderation_request_id',
    'direction',
    'author_actor_id',
    'author_user_id',
    'message',
])]
class VenueModerationMessage extends Model
{
    public function moderationRequest(): BelongsTo
    {
        return $this->belongsTo(VenueModerationRequest::class, 'venue_moderation_request_id');
    }

    public function authorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'author_actor_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    protected function casts(): array
    {
        return [
            'venue_moderation_request_id' => 'integer',
            'direction' => VenueModerationMessageDirectionEnum::class,
            'author_actor_id' => 'integer',
            'author_user_id' => 'integer',
        ];
    }
}
