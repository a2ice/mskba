<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'family',
    'number',
    'name',
    'status',
    'starts_at',
    'expires_at',
    'assigned_by',
    'assigned_at',
    'assigner',
    'comment',
])]
class Contract extends Model
{
    use Auditable;

    public function membership(): HasOne
    {
        return $this->hasOne(ContractMembership::class);
    }

    public function relation(): HasOne
    {
        return $this->hasOne(ContractRelation::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ContractPermission::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => ContractFamilyEnum::class,
            'status' => ContractStatusEnum::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'assigned_at' => 'datetime',
        ];
    }
}
