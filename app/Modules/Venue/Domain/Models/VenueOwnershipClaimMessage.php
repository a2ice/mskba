<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'conversation_id',
    'author_actor_id',
    'client_id',
    'type',
    'body',
    'attachment_disk',
    'attachment_path',
    'attachment_name',
    'attachment_mime',
    'attachment_size',
])]
class VenueOwnershipClaimMessage extends Model
{
    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaimConversation::class, 'conversation_id');
    }

    public function authorActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'author_actor_id');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }
}
