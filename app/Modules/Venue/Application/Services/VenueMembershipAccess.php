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
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Builder;

final class VenueMembershipAccess
{
    public function allows(User $user, Venue $venue, VenuePermissionEnum $permission): bool
    {
        return $this->baseQuery($user, $permission)
            ->where('scope_id', $venue->id)
            ->exists();
    }

    public function allowsForCourt(User $user, VenueCourt $court, VenuePermissionEnum $permission): bool
    {
        if ($court->trashed() || $court->venue?->trashed()) {
            return false;
        }

        if ($this->allows($user, $court->venue, $permission)) {
            return true;
        }

        return $this->baseQuery($user, $permission, ContractMembershipScopeTypeEnum::VENUE_COURT)
            ->where('scope_id', $court->id)
            ->exists();
    }

    public function hasActiveOwner(Venue $venue): bool
    {
        return $this->baseOwnerQuery()
            ->where('scope_id', $venue->id)
            ->exists();
    }

    public function activeOwnerMembership(Venue $venue): ?ContractMembership
    {
        return $this->baseOwnerQuery()
            ->with(['user.profile', 'contract'])
            ->where('scope_id', $venue->id)
            ->latest('id')
            ->first();
    }

    public function activeOwner(Venue $venue): ?User
    {
        $user = $this->activeOwnerMembership($venue)?->user;
        if ($user === null) {
            return null;
        }

        return $user->canonical()->loadMissing('profile');
    }

    /** @return array<int> */
    public function allowedUserIdsForCourt(VenueCourt $court, VenuePermissionEnum $permission): array
    {
        $ids = $this->allowedUserIdsForVenue($court->venue, $permission);
        $now = now();
        $courtIds = ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE_COURT->value)
            ->where('scope_id', $court->id)
            ->whereHas('contract', function (Builder $query) use ($permission, $now): void {
                $query->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                    ->whereHas('permissions', fn (Builder $query) => $query->where('permission', $permission->value));
            })->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_unique([...$ids, ...$courtIds]));
    }

    /** @return array<int> */
    public function allowedCourtIdsFor(User $user, VenuePermissionEnum $permission): array
    {
        return $this->baseQuery($user, $permission, ContractMembershipScopeTypeEnum::VENUE_COURT)
            ->distinct()->pluck('scope_id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<int> */
    public function allowedUserIdsForVenue(Venue $venue, VenuePermissionEnum $permission): array
    {
        $now = now();

        return ContractMembership::query()
            ->where('scope_type', ContractMembershipScopeTypeEnum::VENUE->value)
            ->where('scope_id', $venue->id)
            ->whereHas('contract', function (Builder $query) use ($permission, $now): void {
                $query
                    ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->where(function (Builder $query) use ($now): void {
                        $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                    })
                    ->where(function (Builder $query) use ($now): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    })
                    ->whereHas('permissions', fn (Builder $query) => $query->where('permission', $permission->value));
            })
            ->pluck('user_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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
        $venues = $this->baseContractQuery($user)->distinct()->pluck('scope_id');
        $courtIds = $this->baseContractQuery($user, ContractMembershipScopeTypeEnum::VENUE_COURT)
            ->distinct()->pluck('scope_id');
        $courtVenues = VenueCourt::query()->whereIn('id', $courtIds)
            ->pluck('venue_id');

        return $venues->concat($courtVenues)->map(fn ($id): int => (int) $id)
            ->unique()->values()->all();
    }

    /**
     * @return array<int>
     */
    public function bootstrapOwnedVenueIdsFor(User $user): array
    {
        $user = $user->canonical();

        return Venue::query()
            ->whereHas('creatorActor', fn (Builder $query) => $query->whereIn('user_id', $user->identityIds()))
            ->whereNotIn('id', $this->activeOwnerVenueIds())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function baseContractQuery(User $user, ContractMembershipScopeTypeEnum $scope = ContractMembershipScopeTypeEnum::VENUE): Builder
    {
        $now = now();
        $user = $user->canonical();

        return ContractMembership::query()
            ->where('scope_type', $scope->value)
            ->whereIn('user_id', $user->identityIds())
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

    private function baseQuery(
        User $user,
        VenuePermissionEnum $permission,
        ContractMembershipScopeTypeEnum $scope = ContractMembershipScopeTypeEnum::VENUE,
    ): Builder {
        return $this->baseContractQuery($user, $scope)
            ->whereHas('contract.permissions', fn (Builder $query) => $query->where('permission', $permission->value));
    }
}
