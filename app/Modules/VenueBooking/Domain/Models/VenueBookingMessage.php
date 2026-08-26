<?php

namespace App\Modules\VenueBooking\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'public_id', 'conversation_id', 'author_actor_id', 'client_id', 'type', 'body',
    'attachment_disk', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
])]
class VenueBookingMessage extends Model
{
    public const UPDATED_AT = null;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(VenueBookingConversation::class, 'conversation_id');
    }

    public function authorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'author_actor_id');
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Conversation messages are immutable.'));
        static::deleting(static fn () => throw new LogicException('Conversation messages are immutable.'));
    }

    protected function casts(): array
    {
        return ['attachment_size' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
