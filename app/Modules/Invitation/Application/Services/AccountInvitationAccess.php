<?php

namespace App\Modules\Invitation\Application\Services;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
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

    public function canMutate(?User $actor): bool
    {
        // Explicit review of a held invitation remains superadmin-only.
        return $this->canViewAny($actor)
            && $actor->canonical()->system_role === Role::SUPERADMIN;
    }

    public function canIssueAny(?User $actor): bool
    {
        return $this->canViewAny($actor)
            && app(UserOperationalPermissionChecker::class)->allows($actor, Permission::BACKOFFICE_INVITATION_ISSUE);
    }

    public function issueVenues(Builder $query, ?User $actor): Builder
    {
        if (! $this->canIssueAny($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $actor = $actor->canonical();
        if ($actor->system_role === Role::SUPERADMIN) {
            return $query;
        }

        $memberships = app(VenueMembershipAccess::class);

        return $query->whereKey(array_intersect(
            $memberships->allowedVenueIdsFor($actor, VenuePermissionEnum::MANAGE_MEMBERSHIPS),
            $memberships->allowedVenueIdsFor($actor, VenuePermissionEnum::VIEW),
        ));
    }

    public function canIssue(?User $actor, Venue $venue, string $role): bool
    {
        return $this->canIssueAny($actor) && $this->canGrantRole($actor, $venue, $role);
    }

    public function canManage(?User $actor, AccountInvitation $record): bool
    {
        if (! $this->canViewAny($actor) || $record->scope_type !== 'venue'
            || ! app(UserOperationalPermissionChecker::class)->allows($actor, Permission::BACKOFFICE_INVITATION_MANAGE)) {
            return false;
        }

        $venue = Venue::query()->find($record->scope_id);

        return $venue !== null && $this->canGrantRole($actor, $venue, $record->role);
    }

    public function canGrantRole(User $actor, Venue $venue, string $role): bool
    {
        $actor = $actor->canonical();
        if (! $this->canViewAny($actor) || $venue->trashed()) {
            return false;
        }

        if ($actor->system_role === Role::SUPERADMIN) {
            return in_array($role, ['owner', 'staff', 'manager', 'booking_operator'], true);
        }

        // No ownership or privileged role assignment through invitation delegation.
        if (! in_array($role, ['staff', 'manager', 'booking_operator'], true)) {
            return false;
        }

        return $this->canGrantPermissions($actor, $venue, $role, VenueRole::from($role)->defaultPermissions());
    }

    /** @param list<VenuePermissionEnum> $permissions */
    public function canGrantPermissions(User $actor, Venue $venue, string $role, array $permissions): bool
    {
        $actor = $actor->canonical();
        if (! $this->canViewAny($actor) || $actor->isBlocked() || $venue->trashed()
            || ! in_array($role, ['staff', 'manager', 'booking_operator'], true)) {
            return false;
        }
        foreach ($permissions as $permission) {
            if (! in_array($permission, VenueRole::from($role)->allowedPermissions(), true)) {
                return false;
            }
        }
        if ($actor->system_role === Role::SUPERADMIN) {
            return true;
        }

        $memberships = app(VenueMembershipAccess::class);
        foreach ([VenuePermissionEnum::MANAGE_MEMBERSHIPS, ...$permissions] as $permission) {
            if (! $memberships->allows($actor, $venue, $permission)) {
                return false;
            }
        }

        return true;
    }
}
