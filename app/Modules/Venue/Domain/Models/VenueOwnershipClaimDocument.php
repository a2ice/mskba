<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\Actor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'venue_ownership_claim_id', 'uploaded_by_actor_id',
    'disk', 'path', 'name', 'mime', 'size',
])]
class VenueOwnershipClaimDocument extends Model
{
    protected static function booted(): void
    {
        static::creating(function (VenueOwnershipClaimDocument $document): void {
            $document->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaim::class, 'venue_ownership_claim_id');
    }

    public function uploadedByActor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'uploaded_by_actor_id');
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
