<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'public_id',
    'venue_id',
    'applicant_user_id',
    'status',
    'evidence',
    'decision_reason',
    'reviewer_user_id',
    'owner_contract_membership_id',
    'active_marker',
    'submitted_at',
    'decided_at',
    'cancelled_at',
])]
class VenueOwnershipClaim extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::creating(function (VenueOwnershipClaim $claim): void {
            $claim->public_id ??= (string) Str::uuid();
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

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function ownerContractMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'owner_contract_membership_id');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(VenueOwnershipClaimConversation::class, 'venue_ownership_claim_id');
    }

    protected function casts(): array
    {
        return [
            'status' => VenueOwnershipClaimStatusEnum::class,
            'active_marker' => 'boolean',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @param array<string, mixed> $attributes */
    protected function auditAttributes(array $attributes): array
    {
        $ignored = array_flip([
            ...config('audit.ignored_attributes', []),
            'evidence',
        ]);

        return collect($attributes)
            ->reject(fn (mixed $value, string $key): bool => array_key_exists($key, $ignored))
            ->all();
    }
}
