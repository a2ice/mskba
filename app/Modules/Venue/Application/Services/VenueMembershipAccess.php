<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

final class VenueMembershipAccess
{
    public function allows(User $user, Venue $venue, VenuePermissionEnum $permission): bool
    {
        return $this->baseQuery($user, $permission)
            ->where('scope_id', $venue->id)
            ->exists();
    }

    public function hasActiveOwner(Venue $venue): bool
    {
        return $this->baseOwnerQuery()
            ->where('scope_id', $venue->id)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function allowedVenueIdsFor(User $user, VenuePermissionEnum $permission): array
    {
        return $this->baseQuery($user, $permission)
            ->distinct()
            ->pluck('scope_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int>
     */
    public function contractedVenueIdsFor(User $user): array
    {
        return $this->baseContractQuery($user)
            ->distinct()
            ->pluck('scope_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int>
     */
    public function bootstrapOwnedVenueIdsFor(User $user): array
    {
        return Venue::query()
            ->whereHas('creatorActor', fn (Builder $query) => $query->where('user_id', $user->id))
            ->whereNotIn('id', $this->activeOwnerVenueIds())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function baseContractQuery(User $user): Builder
    {
        $now = now();

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('user_id', $user->id)
            ->whereHas('contract', function (Builder $query) use ($now): void {
                $query
                    ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->where(function (Builder $query) use ($now): void {
                        $query
                            ->whereNull('starts_at')
                            ->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function (Builder $query) use ($now): void {
                        $query
                            ->whereNull('expires_at')
                            ->orWhere('expires_at', '>', $now);
                    });
            });
    }

    private function baseOwnerQuery(): Builder
    {
        $now = now();

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('access_level', VenueMembershipAccessLevelEnum::OWNER->value)
            ->whereHas('contract', function (Builder $query) use ($now): void {
                $query
                    ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->where(function (Builder $query) use ($now): void {
                        $query
                            ->whereNull('starts_at')
                            ->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function (Builder $query) use ($now): void {
                        $query
                            ->whereNull('expires_at')
                            ->orWhere('expires_at', '>', $now);
                    });
            });
    }

    /**
     * @return array<int>
     */
    public function activeOwnerVenueIds(): array
    {
        return $this->baseOwnerQuery()
            ->distinct()
            ->pluck('scope_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function baseQuery(User $user, VenuePermissionEnum $permission): Builder
    {
        return $this->baseContractQuery($user)
            ->whereHas('contract.permissions', fn (Builder $query) => $query->where('permission', $permission->value));
    }
}
