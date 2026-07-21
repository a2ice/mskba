<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['venue_id', 'created_by_actor_id', 'payload', 'applied_at'])]
final class VenueRevision extends Model
{
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function createdByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'created_by_actor_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function moderationRequests(): HasMany
    {
        return $this->hasMany(ModerationRequest::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
