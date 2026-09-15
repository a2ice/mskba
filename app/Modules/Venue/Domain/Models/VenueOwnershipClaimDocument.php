<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'venue_ownership_claim_id', 'path', 'name', 'mime', 'size'])]
final class VenueOwnershipClaimDocument extends Model
{
    protected static function booted(): void
    {
        self::creating(fn (self $document) => $document->public_id ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaim::class, 'venue_ownership_claim_id');
    }
}
