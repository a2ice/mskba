<?php

namespace App\Modules\Event\Infrastructure\Observers;

use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;

final class GameRosterEntryObserver
{
    public function creating(GameRosterEntry $entry): bool|null
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

        if ($membership->member_type !== TeamMemberTypeEnum::PLAYER) {
            return false;
        }

        $entry->lineup_role = $membership->is_default_starter
            ? GameLineupRoleEnum::STARTER
            : GameLineupRoleEnum::BENCH;
        $entry->is_captain = (bool) $membership->is_captain;

        return null;
    }
}
