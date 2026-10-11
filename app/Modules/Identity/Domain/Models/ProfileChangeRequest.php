<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'status', 'original_data', 'proposed_data', 'reason', 'review_reason',
    'reviewed_by_user_id', 'reviewed_at', 'blocked_at', 'unblocked_by_user_id', 'unblocked_at', 'cancelled_at'])]
final class ProfileChangeRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const BLOCKED = 'blocked';
    public const CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProfileChangeRequestEvent::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'original_data' => 'array',
            'proposed_data' => 'array',
            'reviewed_at' => 'datetime',
            'blocked_at' => 'datetime',
            'unblocked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
