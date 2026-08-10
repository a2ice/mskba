<?php

namespace App\Modules\Event\Infrastructure\Observers;

use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Team\Domain\Enums\TeamLineupAssignmentEnum;
use App\Modules\Team\Domain\Models\TeamSportLineupMember;

final class GameRosterEntryObserver
{
    public function creating(GameRosterEntry $entry): ?bool
    {
        $entry->lineup_role ??= GameLineupRoleEnum::BENCH;
        $entry->is_captain ??= false;

        if ($entry->source_contract_membership_id === null) {
            return null;
        }

        $membership = $entry->sourceContractMembership()->first();
        if ($membership === null) {
            return null;
        }

        if (! $membership->isPlayingMember()) {
            return false;
        }

        $sportType = $entry->game()->value('scoring_type');
        $sportAssignment = $sportType === null ? null : TeamSportLineupMember::query()
            ->where('contract_membership_id', $membership->id)
            ->whereHas('sportProfile', fn ($profile) => $profile
                ->where('team_id', $membership->scope_id)
                ->where('sport_type', $sportType))
            ->value('assignment');
        $isStarter = $sportAssignment !== null
            ? TeamLineupAssignmentEnum::tryFrom(
                $sportAssignment instanceof TeamLineupAssignmentEnum ? $sportAssignment->value : (string) $sportAssignment,
            ) === TeamLineupAssignmentEnum::STARTER
            : $membership->is_default_starter;
        $entry->lineup_role = $isStarter
            ? GameLineupRoleEnum::STARTER
            : GameLineupRoleEnum::BENCH;
        $entry->is_captain = (bool) $membership->is_captain;

        return null;
    }
}
