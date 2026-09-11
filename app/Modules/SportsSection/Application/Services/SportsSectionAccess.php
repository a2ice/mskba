<?php

namespace App\Modules\SportsSection\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use Illuminate\Database\Eloquent\Builder;

final class SportsSectionAccess
{
    public function allows(User $user, SportsSection $section, SportsSectionPermissionEnum $permission): bool
    {
        return $this->baseQuery($user, $section)
            ->whereHas('contract.permissions', fn (Builder $query) => $query->where('permission', $permission->value))
            ->exists();
    }

    public function isOwner(User $user, SportsSection $section): bool
    {
        return $this->baseQuery($user, $section)
            ->where('access_level', SportsSectionAccessLevelEnum::OWNER->value)
            ->exists();
    }

    public function activeMemberships(SportsSection $section): Builder
    {
        $now = now();

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
            ->where('scope_id', $section->id)
            ->whereHas('contract', fn (Builder $query) => $query
                ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)));
    }

    private function baseQuery(User $user, SportsSection $section): Builder
    {
        $user = $user->canonical();

        return $this->activeMemberships($section)->whereIn('user_id', $user->identityIds());
    }
}
