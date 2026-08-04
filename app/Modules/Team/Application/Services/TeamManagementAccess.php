<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;

final class TeamManagementAccess
{
    public function isCreator(Team $team, Actor $actor): bool
    {
        $user = $actor->user;

        return $user !== null
            && ! $user->isBlocked()
            && ! $user->trashed()
            && $team->createdByActor()->where('user_id', $user->id)->exists();
    }

    public function allows(Team $team, Actor $actor, TeamPermissionEnum $permission): bool
    {
        $user = $actor->user;
        if ($user === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($this->isCreator($team, $actor)) {
            return true;
        }

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $team->id)
            ->where('user_id', $user->id)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permission', $permission->value)))
            ->exists();
    }

    public function canManage(Team $team, Actor $actor): bool
    {
        foreach (TeamPermissionEnum::cases() as $permission) {
            if ($this->allows($team, $actor, $permission)) {
                return true;
            }
        }

        return false;
    }
}
