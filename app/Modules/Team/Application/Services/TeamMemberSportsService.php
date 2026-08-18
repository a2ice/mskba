<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TeamMemberSportsService
{
    public function __construct(
        private readonly TeamManagementAccess $access,
        private readonly TeamRosterService $rosters,
    ) {}

    /** @param array<int, TeamMemberTypeEnum|string> $sportRoles */
    public function update(
        Team $team,
        ContractMembership $membership,
        Actor $actor,
        array $sportRoles,
        bool $isCaptain,
        bool $isDefaultStarter,
    ): void {
        DB::transaction(function () use (
            $team,
            $membership,
            $actor,
            $sportRoles,
            $isCaptain,
            $isDefaultStarter,
        ): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);
            if (! $this->access->allows($lockedTeam, $actor, TeamPermissionEnum::MANAGE_ROLES)) {
                throw new InvalidArgumentException('Недостаточно прав для управления спортивными ролями команды.');
            }

            $lockedMembership = $lockedTeam->memberships()
                ->whereKey($membership->id)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMembership->access_level === TeamMembershipAccessLevelEnum::OWNER->value
                && ! in_array((int) $lockedMembership->user_id, $actor->user->identityIds(), true)) {
                throw new InvalidArgumentException('Спортивные роли владельца может менять только сам владелец.');
            }

            $roles = collect($sportRoles)
                ->map(fn ($role) => $role instanceof TeamMemberTypeEnum ? $role : TeamMemberTypeEnum::from((string) $role))
                ->unique(fn (TeamMemberTypeEnum $role) => $role->value)
                ->values();
            $isPlayer = $roles->contains(TeamMemberTypeEnum::PLAYER);
            $wasCaptain = (bool) $lockedMembership->is_captain;

            if ($wasCaptain && (! $isPlayer || ! $isCaptain)) {
                throw new InvalidArgumentException('Сначала назначьте другого игрока капитаном команды.');
            }

            if (! $isPlayer && ($isCaptain || $isDefaultStarter)) {
                throw new InvalidArgumentException('Капитаном и стартовым участником может быть только игрок.');
            }

            $otherCaptainExists = $lockedTeam->memberships()
                ->whereKeyNot($lockedMembership->id)
                ->where('is_captain', true)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->lockForUpdate()
                ->exists();

            if ($isPlayer && ! $otherCaptainExists) {
                $isCaptain = true;
            }

            if ($isCaptain) {
                $lockedTeam->memberships()
                    ->whereKeyNot($lockedMembership->id)
                    ->where('is_captain', true)
                    ->lockForUpdate()
                    ->update(['is_captain' => false]);
            }

            $lockedMembership->update([
                'sport_roles' => $roles->map->value->all(),
                'is_captain' => $isCaptain,
                'is_default_starter' => $isDefaultStarter,
            ]);

            if ($isPlayer) {
                $lockedTeam->load('sportProfiles.lineupMembers');
                $this->rosters->synchronizePlayer($lockedTeam, $lockedMembership->id);
            } else {
                $lockedMembership->sportLineupAssignments()->delete();
            }
        });
    }
}
