<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum as Scope;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueAssignedManager;
use App\Modules\Venue\Domain\Models\VenueCommissionAgent;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Database\Eloquent\Builder;

/** A read-only inventory for the administrative Contracts tab. Never a source of ACL. */
final class VenueContractInventory
{
    public function memberships(Venue $venue, User $actor): Builder
    {
        $this->authorize($actor);
        $courts = VenueCourt::query()->withTrashed()->where('venue_id', $venue->id)->select('id');

        return ContractMembership::query()
            ->where(function (Builder $query) use ($venue, $courts): void {
                $query->where(fn (Builder $q) => $q->where('scope_type', Scope::VENUE->value)->where('scope_id', $venue->id))
                    ->orWhere(fn (Builder $q) => $q->where('scope_type', Scope::VENUE_COURT->value)->whereIn('scope_id', $courts));
            })
            ->whereHas('contract', fn (Builder $q) => $q->where('family', ContractFamilyEnum::MEMBERSHIP->value))
            ->with(['user', 'contract.permissions', 'contract.verification', 'contract.assignedByUser']);
    }

    /** Legacy economic assignments are explicitly NOT presented as proven agreements. */
    public function commissionAssignments(Venue $venue, User $actor): Builder
    {
        $this->authorize($actor);

        return VenueCommissionAgent::query()->where('venue_id', $venue->id)->with('user');
    }

    public function assignedManagers(Venue $venue, User $actor): Builder
    {
        $this->authorize($actor);

        return VenueAssignedManager::query()->where('venue_id', $venue->id)->with('user');
    }

    private function authorize(User $actor): void
    {
        $actor = $actor->canonical()->fresh();
        abort_unless($actor !== null && $actor->isConfirmed() && ! $actor->isBlocked()
            && ! $actor->trashed() && $actor->system_role === UserSystemRoleEnum::SUPERADMIN, 403);
    }
}
