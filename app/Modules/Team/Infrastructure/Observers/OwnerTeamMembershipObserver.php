<?php

namespace App\Modules\Team\Infrastructure\Observers;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Validation\ValidationException;

final class OwnerTeamMembershipObserver
{
    public function creating(ContractMembership $membership): void
    {
        if (! request()->routeIs('teams.store')
            || $membership->scope_type !== ContractMembershipScopeTypeEnum::TEAM
            || $membership->access_level !== TeamMembershipAccessLevelEnum::OWNER->value) {
            return;
        }

        $rawRoles = request()->input('creator_sport_roles', []);
        if (! is_array($rawRoles)) {
            throw ValidationException::withMessages([
                'creator_sport_roles' => 'Спортивные роли должны быть переданы списком.',
            ]);
        }

        $roles = collect($rawRoles)
            ->map(fn ($role) => is_string($role) ? TeamMemberTypeEnum::tryFrom($role) : null);
        if ($roles->contains(null)) {
            throw ValidationException::withMessages([
                'creator_sport_roles' => 'Выбрана неизвестная спортивная роль.',
            ]);
        }

        $roles = $roles->unique(fn (TeamMemberTypeEnum $role) => $role->value)->values();
        $hasPlayerRole = $roles->contains(TeamMemberTypeEnum::PLAYER);
        $primaryRole = collect([
            TeamMemberTypeEnum::PLAYER,
            TeamMemberTypeEnum::COACH,
            TeamMemberTypeEnum::MANAGER,
        ])->first(fn (TeamMemberTypeEnum $role) => $roles->contains($role));

        $membership->sport_roles = $roles->map(fn (TeamMemberTypeEnum $role) => $role->value)->all();
        $membership->member_type = $primaryRole;
        $membership->is_captain = $hasPlayerRole && request()->boolean('creator_is_captain');
        $membership->is_default_starter = $hasPlayerRole && request()->boolean('creator_is_default_starter');
    }

    public function created(ContractMembership $membership): void
    {
        if ($membership->scope_type !== ContractMembershipScopeTypeEnum::TEAM || ! $membership->isPlayingMember()) {
            return;
        }

        $team = Team::query()->with('sportProfiles.lineupMembers')->find($membership->scope_id);
        if ($team !== null) {
            app(TeamRosterService::class)->synchronizePlayer($team, $membership->id);
        }
    }
}
