<?php

namespace App\Modules\Moderation\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'moderation_request_id',
    'sender_id',
    'receiver_id',
    'message',
])]
class ModerationMessage extends Model
{
    public function moderationRequest(): BelongsTo
    {
        return $this->belongsTo(ModerationRequest::class, 'moderation_request_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'receiver_id');
    }

    protected function casts(): array
    {
        return [
            'moderation_request_id' => 'integer',
            'sender_id' => 'integer',
            'receiver_id' => 'integer',
        ];
    }
}
