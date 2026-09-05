<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'venue_id', 'owner_user_id', 'source_claim_id', 'contract_membership_id',
    'status', 'status_reason', 'status_changed_by_user_id', 'status_changed_at',
    'approved_at', 'revoked_at', 'active_marker',
])]
class VenueOwnership extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::creating(function (VenueOwnership $ownership): void {
            $ownership->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function sourceClaim(): BelongsTo
    {
        return $this->belongsTo(VenueOwnershipClaim::class, 'source_claim_id');
    }

    public function contractMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'contract_membership_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VenueOwnershipDocument::class);
    }

    protected function casts(): array
    {
        return [
            'status' => VenueOwnershipStatusEnum::class,
            'status_changed_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'active_marker' => 'boolean',
        ];
    }
}
