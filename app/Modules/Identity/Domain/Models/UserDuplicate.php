<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Identity\Domain\Enums\UserDuplicateStatusEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'duplicate_user_id',
    'status',
    'score',
    'evidence_hash',
    'resolved_evidence_hash',
    'metadata',
    'resolved_by',
    'resolved_at',
])]
class UserDuplicate extends Model
{
    use Auditable;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function duplicateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'duplicate_user_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(UserDuplicateEvidence::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'duplicate_user_id' => 'integer',
            'status' => UserDuplicateStatusEnum::class,
            'score' => 'integer',
            'metadata' => 'array',
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }
}
