<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\Actor;
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

    public function update(
        Team $team,
        ContractMembership $membership,
        Actor $actor,
        TeamMemberTypeEnum $memberType,
        bool $isCaptain,
        bool $isDefaultStarter,
    ): void {
        DB::transaction(function () use (
            $team,
            $membership,
            $actor,
            $memberType,
            $isCaptain,
            $isDefaultStarter,
        ): void {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);
            if (! $this->access->allows($lockedTeam, $actor, TeamPermissionEnum::MANAGE_ROLES)) {
                throw new InvalidArgumentException('Недостаточно прав для управления составом команды.');
            }

            $lockedMembership = $lockedTeam->memberships()
                ->whereKey($membership->id)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->lockForUpdate()
                ->firstOrFail();

            if ($memberType !== TeamMemberTypeEnum::PLAYER && ($isCaptain || $isDefaultStarter)) {
                throw new InvalidArgumentException('Капитаном и стартовым участником может быть только игрок.');
            }

            if ($isCaptain) {
                $lockedTeam->memberships()
                    ->whereKeyNot($lockedMembership->id)
                    ->where('is_captain', true)
                    ->lockForUpdate()
                    ->update(['is_captain' => false]);
            }

            $lockedMembership->update([
                'member_type' => $memberType,
                'is_captain' => $isCaptain,
                'is_default_starter' => $isDefaultStarter,
            ]);
            if ($memberType === TeamMemberTypeEnum::PLAYER) {
                $lockedTeam->load('sportProfiles.lineupMembers');
                $this->rosters->synchronizePlayer($lockedTeam, $lockedMembership->id);
            } else {
                $lockedMembership->sportLineupAssignments()->delete();
            }
        });
    }
}
