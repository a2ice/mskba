<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractPartyTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueContract;
use Illuminate\Database\Eloquent\Builder;

final class VenueContractAccess
{
    public function allows(User $user, Venue $venue, VenuePermissionEnum $permission): bool
    {
        return $this->baseQuery($user, $permission)
            ->where('venue_id', $venue->id)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function allowedVenueIdsFor(User $user, VenuePermissionEnum $permission): array
    {
        return $this->baseQuery($user, $permission)
            ->distinct()
            ->pluck('venue_id')
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
            ->pluck('venue_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function baseContractQuery(User $user): Builder
    {
        $now = now();

        return VenueContract::query()
            ->whereHas('contract', function (Builder $query) use ($user, $now): void {
                $query
                    ->where('status', ContractStatusEnum::ACTIVE->value)
                    ->whereHas('parties', function (Builder $query) use ($user): void {
                        $query
                            ->where('party_type', ContractPartyTypeEnum::USER->value)
                            ->where('party_id', $user->id);
                    })
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

    private function baseQuery(User $user, VenuePermissionEnum $permission): Builder
    {
        return $this->baseContractQuery($user)
            ->whereHas('permissions', fn (Builder $query) => $query->where('permission', $permission->value));
    }
}
