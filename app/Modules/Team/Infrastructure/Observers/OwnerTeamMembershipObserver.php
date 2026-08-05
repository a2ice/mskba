<?php

namespace App\Modules\Team\Infrastructure\Observers;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
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
        $membership->is_captain = $hasPlayerRole;
        $membership->is_default_starter = $hasPlayerRole && request()->boolean('creator_is_default_starter');
    }

    public function created(ContractMembership $membership): void
    {
        if (! $this->isAcceptedActivePlayer($membership)) {
            return;
        }

        $team = Team::query()->with('sportProfiles.lineupMembers')->find($membership->scope_id);
        if ($team !== null) {
            app(TeamRosterService::class)->synchronizePlayer($team, $membership->id);
        }
    }

    public function updated(ContractMembership $membership): void
    {
        if (! $membership->wasChanged('invitation_status') || ! $this->isAcceptedActivePlayer($membership)) {
            return;
        }

        DB::transaction(function () use ($membership): void {
            $team = Team::query()->whereKey($membership->scope_id)->lockForUpdate()->first();
            if ($team === null) {
                return;
            }

            $captainExists = $team->memberships()
                ->whereKeyNot($membership->id)
                ->where('is_captain', true)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();

            if (! $captainExists) {
                ContractMembership::query()->whereKey($membership->id)->update(['is_captain' => true]);
            }
        });
    }

    private function isAcceptedActivePlayer(ContractMembership $membership): bool
    {
        return $membership->scope_type === ContractMembershipScopeTypeEnum::TEAM
            && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED
            && $membership->isPlayingMember()
            && $membership->contract()->where('status', ContractStatusEnum::ACTIVE->value)->exists();
    }
}
