<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id', 'status', 'source_type', 'source_id', 'reviewer_user_id',
    'verified_at', 'expires_at', 'decision_reason',
])]
final class ContractVerification extends Model
{
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function isValid(): bool
    {
        return $this->status === 'verified'
            && $this->verified_at !== null
            && $this->verified_at->lessThanOrEqualTo(now())
            && ($this->expires_at === null || $this->expires_at->greaterThan(now()));
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
