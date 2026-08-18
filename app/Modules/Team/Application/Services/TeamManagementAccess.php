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
        $user = $actor->user?->canonical();

        return $user !== null
            && ! $user->isBlocked()
            && ! $user->trashed()
            && $team->createdByActor()->whereIn('user_id', $user->identityIds())->exists();
    }

    public function allows(Team $team, Actor $actor, TeamPermissionEnum $permission): bool
    {
        $user = $actor->user?->canonical();
        if ($user === null || $user->isBlocked() || $user->trashed()) {
            return false;
        }

        if ($this->isCreator($team, $actor)) {
            return true;
        }

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->where('scope_id', $team->id)
            ->whereIn('user_id', $user->identityIds())
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

    public function canManageMembersAndRoster(Team $team, Actor $actor): bool
    {
        foreach ([
            TeamPermissionEnum::MANAGE_ROSTER,
            TeamPermissionEnum::INVITE_MEMBERS,
            TeamPermissionEnum::MANAGE_ROLES,
            TeamPermissionEnum::MANAGE_PERMISSIONS,
            TeamPermissionEnum::REMOVE_MEMBERS,
        ] as $permission) {
            if ($this->allows($team, $actor, $permission)) {
                return true;
            }
        }

        return false;
    }
}
