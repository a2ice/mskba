<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use InvalidArgumentException;

final class TournamentAccess
{
    public function canManage(Tournament $tournament, Actor $actor): bool
    {
        return collect(TournamentPermissionEnum::cases())
            ->contains(fn (TournamentPermissionEnum $permission): bool => $this->allows($tournament, $actor, $permission));
    }

    public function assertCanManage(Tournament $tournament, Actor $actor): void
    {
        if (! $this->canManage($tournament, $actor)) {
            throw new InvalidArgumentException('У вас нет права управлять этим турниром.');
        }
    }

    public function isOwner(Tournament $tournament, Actor $actor): bool
    {
        return (int) $actor->id === (int) $tournament->created_by_actor_id
            || ($actor->user_id !== null && $tournament->createdByActor()->where('user_id', $actor->user_id)->exists());
    }

    public function allows(Tournament $tournament, Actor $actor, TournamentPermissionEnum $permission): bool
    {
        if ($this->isOwner($tournament, $actor)) {
            return true;
        }
        if ($actor->user_id === null) {
            return false;
        }

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::TOURNAMENT->value)
            ->where('scope_id', $tournament->id)
            ->where('user_id', $actor->user_id)
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($query) => $query
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn ($dates) => $dates->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('permissions', fn ($permissions) => $permissions->where('permission', $permission->value)))
            ->exists();
    }

    public function assertAllows(Tournament $tournament, Actor $actor, TournamentPermissionEnum $permission): void
    {
        if (! $this->allows($tournament, $actor, $permission)) {
            throw new InvalidArgumentException('У вас нет права управлять этим турниром.');
        }
    }

    /** @return list<TournamentPermissionEnum> */
    public function effectivePermissions(Tournament $tournament, Actor $actor): array
    {
        return array_values(array_filter(
            TournamentPermissionEnum::cases(),
            fn (TournamentPermissionEnum $permission): bool => $this->allows($tournament, $actor, $permission),
        ));
    }
}
