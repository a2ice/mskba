<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['owner_user_id', 'scope_type', 'scope_id', 'name', 'permissions', 'shared'])]
final class ContractTemplate extends Model
{
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function casts(): array
    {
        return [
            'scope_type' => ContractMembershipScopeTypeEnum::class,
            'scope_id' => 'integer',
            'permissions' => 'array',
            'shared' => 'boolean',
        ];
    }
}
