<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'contract_id',
    'scope_type',
    'scope_id',
    'user_id',
    'access_level',
    'member_type',
    'is_captain',
    'is_default_starter',
])]
class ContractMembership extends Model
{
    use Auditable;

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPlayingMember(): bool
    {
        return $this->member_type === TeamMemberTypeEnum::PLAYER;
    }

    protected function casts(): array
    {
        return [
            'scope_type' => ContractMembershipScopeTypeEnum::class,
            'member_type' => TeamMemberTypeEnum::class,
            'is_captain' => 'boolean',
            'is_default_starter' => 'boolean',
        ];
    }
}
