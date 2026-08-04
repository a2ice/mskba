<?php

namespace App\Modules\Contract\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\TeamSportLineupMember;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contract_id',
    'scope_type',
    'scope_id',
    'user_id',
    'access_level',
    'member_type',
    'is_captain',
    'is_default_starter',
    'invitation_status',
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

    public function sportLineupAssignments(): HasMany
    {
        return $this->hasMany(TeamSportLineupMember::class);
    }

    protected function casts(): array
    {
        return [
            'scope_type' => ContractMembershipScopeTypeEnum::class,
            'member_type' => TeamMemberTypeEnum::class,
            'is_captain' => 'boolean',
            'is_default_starter' => 'boolean',
            'invitation_status' => TeamInvitationStatusEnum::class,
        ];
    }
}
