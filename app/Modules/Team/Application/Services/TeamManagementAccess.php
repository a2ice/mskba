<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Models\Team;

final class TeamManagementAccess
{
    public function canManage(Team $team, Actor $actor): bool
    {
        if ($actor->user?->isConfirmed() === true
            && $actor->user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            return true;
        }

        if ($actor->user_id === null) {
            return false;
        }

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $team->id)
            ->where('user_id', $actor->user_id)
            ->whereIn('access_level', [
                TeamMembershipAccessLevelEnum::OWNER->value,
                TeamMembershipAccessLevelEnum::RESPONSIBLE->value,
            ])
            ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
            ->exists();
    }
}
