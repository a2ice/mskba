<?php

namespace App\Modules\Team\Application\Services;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;

final class TeamMembershipHierarchy
{
    public function canRemove(ContractMembership $actor, ContractMembership $target): bool
    {
        return $this->rank($actor->access_level) > $this->rank($target->access_level);
    }

    public function rank(TeamMembershipAccessLevelEnum|string|null $level): int
    {
        $value = $level instanceof TeamMembershipAccessLevelEnum ? $level->value : $level;

        return match ($value) {
            TeamMembershipAccessLevelEnum::OWNER->value => 500,
            TeamMembershipAccessLevelEnum::RESPONSIBLE->value => 400,
            TeamMembershipAccessLevelEnum::CAPTAIN->value => 300,
            TeamMembershipAccessLevelEnum::COACH->value => 200,
            TeamMembershipAccessLevelEnum::PLAYER->value => 100,
            default => 0,
        };
    }
}
