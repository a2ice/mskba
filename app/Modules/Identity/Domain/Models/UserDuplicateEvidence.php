<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\UserDuplicateEvidenceTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_duplicate_id',
    'type',
    'value_hash',
    'metadata',
    'is_active',
    'first_seen_at',
    'last_seen_at',
])]
class UserDuplicateEvidence extends Model
{
    protected $table = 'user_duplicate_evidence';

    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(UserDuplicate::class, 'user_duplicate_id');
    }

    protected function casts(): array
    {
        return [
            'type' => UserDuplicateEvidenceTypeEnum::class,
            'metadata' => 'array',
            'is_active' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
