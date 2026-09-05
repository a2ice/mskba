<?php

namespace App\Modules\Venue\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'venue_id', 'user_id', 'type', 'reason', 'imposed_by_user_id', 'imposed_at',
    'revoked_by_user_id', 'revoked_at', 'revoke_reason', 'active_marker',
])]
class VenueUserRestriction extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::creating(function (VenueUserRestriction $restriction): void {
            $restriction->public_id ??= (string) Str::uuid();
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imposed_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'type' => VenueUserRestrictionTypeEnum::class,
            'imposed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'active_marker' => 'boolean',
        ];
    }
}
