<?php

namespace App\Modules\Invitation\Application\Services;

use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

final class AccountInvitationAccess
{
    public function canViewAny(?User $actor): bool
    {
        $actor = $actor?->canonical();
        if ($actor === null || ! $actor->isConfirmed() || $actor->trashed()
            || ! in_array($actor->system_role, [Role::SUPERADMIN, Role::ADMIN, Role::MANAGER], true)) {
            return false;
        }

        $checker = app(UserOperationalPermissionChecker::class);

        return $checker->allows($actor, Permission::BACKOFFICE_ACCESS)
            && $checker->allows($actor, Permission::BACKOFFICE_INVITATION_VIEW);
    }

    public function constrain(Builder $query, ?User $actor): Builder
    {
        if (! $this->canViewAny($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $actor = $actor->canonical();
        if ($actor->system_role === Role::SUPERADMIN) {
            return $query;
        }

        // Existing venue-level authority only. Court-level permissions and
        // unverified/expired contracts cannot reveal another venue's invitations.
        $venueIds = app(VenueMembershipAccess::class)->allowedVenueIdsFor(
            $actor, VenuePermissionEnum::MANAGE_MEMBERSHIPS,
        );

        return $query->where('scope_type', 'venue')->whereIn('scope_id',
            Venue::query()->whereKey($venueIds)->select('id'));
    }

    public function canView(?User $actor, AccountInvitation $invitation): bool
    {
        return $this->constrain(AccountInvitation::query(), $actor)
            ->whereKey($invitation->id)->exists();
    }

    // Mutations remain restricted until the contract completion policy and
    // explicit staff authorization workflow are implemented.
    public function canMutate(?User $actor): bool
    {
        return $this->canViewAny($actor)
            && $actor->canonical()->system_role === Role::SUPERADMIN;
    }
}
