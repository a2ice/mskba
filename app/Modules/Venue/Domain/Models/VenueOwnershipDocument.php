<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipDocumentTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'venue_ownership_id', 'type', 'source_claim_message_id',
    'added_by_user_id', 'disk', 'path', 'name', 'mime', 'size', 'note',
])]
class VenueOwnershipDocument extends Model
{
    protected static function booted(): void
    {
        static::creating(function (VenueOwnershipDocument $document): void {
            $document->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function ownership(): BelongsTo
    {
        return $this->belongsTo(VenueOwnership::class);
    }

    public function sourceClaimMessage(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaimMessage::class, 'source_claim_message_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => VenueOwnershipDocumentTypeEnum::class,
            'size' => 'integer',
        ];
    }
}
