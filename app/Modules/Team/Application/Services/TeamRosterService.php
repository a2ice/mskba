<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamSportLineupMember;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TeamRosterService
{
    /** @param list<int> $starterIds @param list<int> $reserveIds */
    public function save(Team $team, TeamSportTypeEnum $sport, array $starterIds, array $reserveIds): void
    {
        DB::transaction(function () use ($team, $sport, $starterIds, $reserveIds): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $profile = $team->sportProfiles()->where('sport_type', $sport->value)->lockForUpdate()->firstOrFail();
            $membershipIds = $team->memberships()
                ->withSportRole(TeamMemberTypeEnum::PLAYER)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $submitted = [...$starterIds, ...$reserveIds];
            if (count($submitted) !== count(array_unique($submitted))
                || array_values(array_diff($membershipIds, $submitted)) !== []
                || array_values(array_diff($submitted, $membershipIds)) !== []) {
                throw new InvalidArgumentException('Состав изменился. Обновите страницу и повторите сохранение.');
            }

            $required = $sport === TeamSportTypeEnum::STREETBALL ? 3 : 5;
            if (count($starterIds) > $required) {
                throw new InvalidArgumentException("В основном составе «{$sport->label()}» не может быть больше {$required} игроков.");
            }
            if (count($membershipIds) >= $required && count($starterIds) !== $required) {
                throw new InvalidArgumentException("В основном составе «{$sport->label()}» должно быть {$required} игроков.");
            }

            TeamSportLineupMember::query()->where('team_sport_profile_id', $profile->id)->delete();
            foreach ([TeamLineupAssignmentEnum::STARTER->value => $starterIds, TeamLineupAssignmentEnum::RESERVE->value => $reserveIds] as $assignment => $ids) {
                foreach ($ids as $position => $membershipId) {
                    TeamSportLineupMember::create([
                        'team_sport_profile_id' => $profile->id,
                        'contract_membership_id' => $membershipId,
                        'assignment' => $assignment,
                        'position' => $position,
                    ]);
                }
            }
        });
    }

    public function synchronizePlayer(Team $team, int $membershipId): void
    {
        foreach ($team->sportProfiles as $profile) {
            $position = (int) $profile->lineupMembers()->where('assignment', TeamLineupAssignmentEnum::RESERVE->value)->max('position') + 1;
            $profile->lineupMembers()->firstOrCreate(
                ['contract_membership_id' => $membershipId],
                ['assignment' => TeamLineupAssignmentEnum::RESERVE, 'position' => $position],
            );
        }
    }
}
