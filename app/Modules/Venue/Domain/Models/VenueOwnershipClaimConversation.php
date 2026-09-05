<?php

namespace App\Modules\Venue\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['public_id', 'venue_ownership_claim_id'])]
class VenueOwnershipClaimConversation extends Model
{
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaim::class, 'venue_ownership_claim_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VenueOwnershipClaimMessage::class, 'conversation_id');
    }
}
