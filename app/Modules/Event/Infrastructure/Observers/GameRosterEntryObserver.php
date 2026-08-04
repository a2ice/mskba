<?php

namespace App\Modules\Event\Infrastructure\Observers;

use App\Modules\Event\Domain\Enums\GameLineupRoleEnum;
use App\Modules\Event\Domain\Models\GameRosterEntry;

final class GameRosterEntryObserver
{
    public function creating(GameRosterEntry $entry): void
    {
        $entry->lineup_role ??= GameLineupRoleEnum::BENCH;
        $entry->is_captain ??= false;

        if ($entry->source_contract_membership_id === null) {
            return;
        }

        $membership = $entry->sourceContractMembership()->first();
        if ($membership === null) {
            return;
        }

        $entry->lineup_role = $membership->is_default_starter
            ? GameLineupRoleEnum::STARTER
            : GameLineupRoleEnum::BENCH;
        $entry->is_captain = (bool) $membership->is_captain;
    }
}
